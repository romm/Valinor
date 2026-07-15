<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Definition\Attributes;
use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ArrayTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ConverterTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\HttpRequestTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\InterfacePassthroughTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\InterfaceTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\KeyConverterTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ListTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\MixedTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\NullTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ObjectTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ScalarTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ShapedArrayTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ShapedListTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\TypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\UndefinedObjectTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\UnionTypeMapper;
use CuyZ\Valinor\Mapper\Object\Arguments;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Object\FunctionObjectBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\InterfaceInferringContainer;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotInferFinalClass;
use CuyZ\Valinor\Mapper\Tree\Exception\InterfaceHasBothConstructorAndInfer;
use CuyZ\Valinor\Mapper\Tree\Exception\ObjectImplementationCallbackError;
use CuyZ\Valinor\Mapper\Tree\Exception\UnresolvableShellType;
use CuyZ\Valinor\Type\Dumper\TypeDumper;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\ArrayType;
use CuyZ\Valinor\Type\Types\InterfaceType;
use CuyZ\Valinor\Type\Types\IterableType;
use CuyZ\Valinor\Type\Types\ListType;
use CuyZ\Valinor\Type\Types\MixedType;
use CuyZ\Valinor\Type\Types\NonEmptyArrayType;
use CuyZ\Valinor\Type\Types\NonEmptyListType;
use CuyZ\Valinor\Type\Types\NullType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\ShapedListType;
use CuyZ\Valinor\Type\Types\UndefinedObjectType;
use CuyZ\Valinor\Type\Types\UnionType;
use CuyZ\Valinor\Type\Types\UnresolvableType;
use RuntimeException;

/** @internal */
final class TypeMapperFactory
{
    public function __construct(
        private ClassDefinitionRepository $classDefinitionRepository,
        private ObjectBuilderFactory $objectBuilderFactory,
        private InterfaceInferringContainer $interfaceInferringContainer,
        private TypeDumper $typeDumper,
        private ConverterAnalyzer $converterAnalyzer,
        private KeyConverterHandler $keyConverterHandler,
        private Settings $settings,
        /**
         * The same type can be compiled twice with different settings, for
         * instance when it is filled with the values of an HTTP request. The
         * variant keeps the compiled methods of the two trees apart.
         */
        private ?string $variant = null,
    ) {}

    /**
     * The values of an HTTP request come from the outside of the application,
     * so extra values can be added anytime and must never be reported. Its
     * route and query values are always strings, so they need to be cast to the
     * type of the element they fill.
     */
    public function forHttpRequest(): self
    {
        $settings = clone $this->settings;
        $settings->allowSuperfluousKeys = true;
        $settings->allowScalarValueCasting = true;

        $self = clone $this;
        $self->settings = $settings;
        $self->variant = 'http-request';

        return $self;
    }

    public function variant(): ?string
    {
        return $this->variant;
    }

    public function dumpType(Type $type): string
    {
        return $this->typeDumper->dump($type);
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * Create a TypeMapper for the given type, optionally wrapping with converters.
     */
    public function for(Type $type, bool $applyConverters = true, ?Attributes $attributes = null): TypeMapper
    {
        if ($type instanceof UnresolvableType) {
            throw new UnresolvableShellType($type);
        }

        $typeMapper = $this->resolveTypeMapper($type);
        $baseMapper = $typeMapper;

        // Collect all converters — order matters: element/argument attrs
        // (outermost) → global → class-level attrs (innermost).
        $allConverters = [];

        // 1. Element/argument attribute converters (checked first)
        if ($attributes !== null) {
            $allConverters = $this->converterAnalyzer->attributeConvertersFor($attributes, $type);
        }

        // 2. Global converters registered for this type
        if ($applyConverters) {
            $allConverters = [...$allConverters, ...$this->converterAnalyzer->matchingConvertersFor($type)];
        }

        // 3. Class-level attribute converters for concrete ObjectType
        if ($type instanceof ObjectType && ! ($type instanceof InterfaceType)) {
            $class = $this->classDefinitionRepository->for($type);

            if (! $class->isAbstract && ! $this->interfaceInferringContainer->has($class->name)) {
                $allConverters = [...$allConverters, ...$this->converterAnalyzer->attributeConvertersFor($class->attributes, $type)];
            }
        }

        if ($allConverters !== []) {
            $typeMapper = new ConverterTypeMapper($type, $typeMapper, $allConverters, $this->variant);
        }

        // Wrap with key converter logic
        if ($this->keyConverterHandler->hasKeyConverters() && ($baseMapper instanceof ObjectTypeMapper || $baseMapper instanceof ShapedArrayTypeMapper)) {
            $typeMapper = new KeyConverterTypeMapper(
                $type,
                $typeMapper,
                $this->keyConverterHandler->keyConverterIndices(),
                $this->variant,
            );
        }

        // Wrap with HTTP request logic, so that a shaped array can be filled
        // with the values of a request. A sealed type only: an unsealed one is
        // rejected by the shaped array mapper itself.
        if ($type instanceof ShapedArrayType && ! $type->isUnsealed()) {
            $typeMapper = new HttpRequestTypeMapper($type, $typeMapper, $this->variant);
        }

        return $typeMapper;
    }

    /**
     * The arguments of an object are mapped through a shaped array that is not
     * part of the type tree, so it cannot go through `for()`. It still needs to
     * be filled with the values of an HTTP request.
     */
    public function forObjectArguments(ShapedArrayType $type): TypeMapper
    {
        $mapper = new ShapedArrayTypeMapper($type, $this->settings, $this->variant);

        if ($type->isUnsealed()) {
            return $mapper;
        }

        return new HttpRequestTypeMapper($type, $mapper, $this->variant);
    }

    private function resolveTypeMapper(Type $type): TypeMapper
    {
        if ($type instanceof ObjectType) {
            $class = $this->classDefinitionRepository->for($type);

            $hasInfer = $this->interfaceInferringContainer->has($class->name);
            $hasRegisteredConstructor = $this->hasRegisteredConstructorFor($type);

            // Validate: interface cannot have both constructor and infer
            if ($hasRegisteredConstructor && $hasInfer) {
                throw new InterfaceHasBothConstructorAndInfer($class->name);
            }

            // Check if this type has an infer function registered
            if ($hasInfer) {
                if ($class->isFinal) {
                    $inferFunction = $this->interfaceInferringContainer->inferFunctionFor($class->name);
                    throw new CannotInferFinalClass($class->name, $inferFunction);
                }

                $inferFunction = $this->interfaceInferringContainer->inferFunctionFor($class->name);
                $inferArguments = Arguments::fromParameters($inferFunction->parameters);

                try {
                    $implementations = $this->interfaceInferringContainer->classImplementationsFor($class->name);
                } catch (ObjectImplementationCallbackError) {
                    // Infer callback threw at compile time (e.g. always-throwing function).
                    // Generate an InterfaceTypeMapper with no implementations — the generated
                    // try/catch will handle the runtime exception gracefully.
                    $implementations = [];
                }

                return new InterfaceTypeMapper(
                    $type,
                    $inferArguments,
                    $implementations,
                    $this->variant,
                );
            }

            // Interface/abstract with registered constructors → use ObjectTypeMapper
            if (($type instanceof InterfaceType || $class->isAbstract) && $hasRegisteredConstructor) {
                return new ObjectTypeMapper(
                    $class,
                    $this->objectBuilderFactory->for($class),
                    $this->settings,
                    $this->variant,
                );
            }

            // Interface/abstract without infer or registered constructors → passthrough
            if ($type instanceof InterfaceType || $class->isAbstract) {
                return new InterfacePassthroughTypeMapper($type);
            }

            return new ObjectTypeMapper(
                $class,
                $this->objectBuilderFactory->for($class),
                $this->settings,
                $this->variant,
            );
        }

        return match (true) {
            $type instanceof ScalarType => new ScalarTypeMapper($type, $this->settings),
            $type instanceof NullType => new NullTypeMapper(),
            $type instanceof MixedType => new MixedTypeMapper($this->settings),
            $type instanceof UndefinedObjectType => new UndefinedObjectTypeMapper($this->settings),
            $type instanceof UnionType => new UnionTypeMapper($type, $this->variant),
            $type instanceof ShapedArrayType => new ShapedArrayTypeMapper($type, $this->settings, $this->variant),
            $type instanceof ShapedListType => new ShapedListTypeMapper($type, $this->settings, $this->variant),
            $type instanceof ListType,
            $type instanceof NonEmptyListType => new ListTypeMapper($type, $this->settings, $this->variant),
            $type instanceof ArrayType,
            $type instanceof NonEmptyArrayType,
            $type instanceof IterableType => new ArrayTypeMapper($type, $this->settings, $this->variant),
            default => throw new RuntimeException('Unsupported type for compiled mapper: ' . $type->toString()),
        };
    }

    private function hasRegisteredConstructorFor(ObjectType $type): bool
    {
        $class = $this->classDefinitionRepository->for($type);
        $builders = $this->objectBuilderFactory->for($class);

        foreach ($builders as $builder) {
            if ($builder instanceof FunctionObjectBuilder) {
                return true;
            }
        }

        return false;
    }

}
