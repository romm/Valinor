<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Definition\Attributes;
use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ArrayTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ConverterTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\InterfacePassthroughTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\InterfaceTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\KeyConverterTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ListTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\MixedTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\NullTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ObjectTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ScalarTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ShapedArrayTypeMapper;
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
use CuyZ\Valinor\Type\Types\UndefinedObjectType;
use CuyZ\Valinor\Type\Types\UnionType;
use CuyZ\Valinor\Type\Types\UnresolvableType;
use RuntimeException;

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
    ) {}

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
            $typeMapper = new ConverterTypeMapper($type, $typeMapper, $allConverters);
        }

        // Wrap with key converter logic
        if ($this->keyConverterHandler->hasKeyConverters() && ($baseMapper instanceof ObjectTypeMapper || $baseMapper instanceof ShapedArrayTypeMapper)) {
            $typeMapper = new KeyConverterTypeMapper(
                $type,
                $typeMapper,
                $this->keyConverterHandler->keyConverterIndices(),
            );
        }

        return $typeMapper;
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
                    $inferFunction,
                    $inferArguments,
                    $implementations,
                );
            }

            // Interface/abstract with registered constructors → use ObjectTypeMapper
            if (($type instanceof InterfaceType || $class->isAbstract) && $hasRegisteredConstructor) {
                return new ObjectTypeMapper(
                    $class,
                    $this->objectBuilderFactory->for($class),
                    $this->settings,
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
            );
        }

        return match (true) {
            $type instanceof ScalarType => new ScalarTypeMapper($type, $this->settings),
            $type instanceof NullType => new NullTypeMapper(),
            $type instanceof MixedType => new MixedTypeMapper($this->settings),
            $type instanceof UndefinedObjectType => new UndefinedObjectTypeMapper($this->settings),
            $type instanceof UnionType => new UnionTypeMapper($type),
            $type instanceof ShapedArrayType => new ShapedArrayTypeMapper($type, $this->settings),
            $type instanceof ListType,
            $type instanceof NonEmptyListType => new ListTypeMapper($type, $this->settings),
            $type instanceof ArrayType,
            $type instanceof NonEmptyArrayType,
            $type instanceof IterableType => new ArrayTypeMapper($type, $this->settings),
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
