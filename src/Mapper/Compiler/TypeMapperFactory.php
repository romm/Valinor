<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Definition\AttributeDefinition;
use CuyZ\Valinor\Definition\Attributes;
use CuyZ\Valinor\Definition\FunctionDefinition;
use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Definition\Repository\FunctionDefinitionRepository;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasInvalidCallableParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasInvalidReturnType;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasNoParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasTooManyParameters;
use CuyZ\Valinor\Mapper\Tree\Exception\KeyConverterHasInvalidStringParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\KeyConverterHasNoParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\KeyConverterHasTooManyParameters;
use CuyZ\Valinor\Type\StringType;
use CuyZ\Valinor\Type\Types\CallableType;
use CuyZ\Valinor\Mapper\Tree\Builder\ConverterContainer;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ArrayTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ConverterTypeMapperWrapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\InterfacePassthroughTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\InterfaceTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ListTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\MixedTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\NullTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ObjectTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ScalarTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ShapedArrayTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\TypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\UndefinedObjectTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\UnionTypeMapper;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Object\Arguments;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Object\FunctionObjectBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\InterfaceInferringContainer;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotInferFinalClass;
use CuyZ\Valinor\Mapper\Tree\Exception\InterfaceHasBothConstructorAndInfer;
use CuyZ\Valinor\Mapper\Tree\Exception\ObjectImplementationCallbackError;
use CuyZ\Valinor\Type\CompositeTraversableType;
use CuyZ\Valinor\Type\CompositeType;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\ArrayType;
use CuyZ\Valinor\Type\Types\Generics;
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
use CuyZ\Valinor\Type\Dumper\TypeDumper;
use CuyZ\Valinor\Mapper\Tree\Exception\UnresolvableShellType;
use RuntimeException;

final class TypeMapperFactory
{
    /** @var array<string, mixed> */
    private array $constructorCallbacks = [];

    /** @var list<array{callable: callable, definition: FunctionDefinition}>|null */
    private ?array $analyzedConverters = null;

    public function __construct(
        private ClassDefinitionRepository $classDefinitionRepository,
        private ObjectBuilderFactory $objectBuilderFactory,
        private InterfaceInferringContainer $interfaceInferringContainer,
        private TypeDumper $typeDumper,
        private ?FunctionDefinitionRepository $functionDefinitionRepository = null,
        /** @var list<callable> */
        private array $converters = [],
        /** @var list<callable(string): string> */
        private array $keyConverters = [],
    ) {}

    public function dumpType(Type $type): string
    {
        return $this->typeDumper->dump($type);
    }

    public function registerConstructorCallback(string $key, mixed $callback): void
    {
        $this->constructorCallbacks[$key] = $callback;
    }

    /**
     * Returns all constructor callbacks registered during compilation.
     * These must be passed to the compiled mapper at instantiation time.
     *
     * @return array<string, mixed>
     */
    public function constructorCallbacks(): array
    {
        return $this->constructorCallbacks;
    }

    public function resetConstructorCallbacks(): void
    {
        $this->constructorCallbacks = [];
        $this->registerConverterCallbacks();
    }

    /**
     * Register all converter callables as constructor callbacks.
     * Called during reset to ensure converters are available for cached mappers.
     */
    private function registerConverterCallbacks(): void
    {
        foreach ($this->converters as $index => $converter) {
            $this->constructorCallbacks['converter_' . $index] = $converter;
        }

        foreach ($this->keyConverters as $index => $keyConverter) {
            $this->constructorCallbacks['key_conv_' . $index] = $keyConverter;
        }
    }

    /**
     * @return bool
     */
    public function hasKeyConverters(): bool
    {
        return $this->keyConverters !== [];
    }

    /**
     * @return list<string> Callback keys for key converters
     */
    public function keyConverterKeys(): array
    {
        $keys = [];

        foreach ($this->keyConverters as $index => $keyConverter) {
            // Validate key converter (same as KeyConverterContainer::checkConverter)
            if ($this->functionDefinitionRepository !== null) {
                $definition = $this->functionDefinitionRepository->for($keyConverter);

                if ($definition->parameters->count() === 0) {
                    throw new KeyConverterHasNoParameter($definition);
                }

                if ($definition->parameters->count() > 1) {
                    throw new KeyConverterHasTooManyParameters($definition);
                }

                if (! $definition->parameters->at(0)->nativeType instanceof StringType) {
                    throw new KeyConverterHasInvalidStringParameter($definition, $definition->parameters->at(0)->nativeType);
                }
            }

            $key = 'key_conv_' . $index;
            $this->registerConstructorCallback($key, $keyConverter);
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Get the infer callback for an interface type.
     *
     * @param class-string $name
     * @return callable
     */
    public function inferCallbackFor(string $name): mixed
    {
        return $this->interfaceInferringContainer->inferCallbackFor($name);
    }

    public function for(Type $type, bool $applyConverters = true): TypeMapper
    {
        if ($type instanceof UnresolvableType) {
            throw new UnresolvableShellType($type);
        }

        $typeMapper = $this->resolveTypeMapper($type);

        // Wrap with converter logic if converters are registered and applicable
        if ($applyConverters && $this->converters !== []) {
            $matchingConverters = $this->matchingConvertersFor($type);

            if ($matchingConverters !== []) {
                $typeMapper = new ConverterTypeMapperWrapper($type, $typeMapper, $matchingConverters);
            }
        }

        // Wrap with class-level attribute converters for concrete ObjectType
        if ($type instanceof ObjectType
            && ! ($type instanceof InterfaceType)
            && $this->functionDefinitionRepository !== null
        ) {
            $class = $this->classDefinitionRepository->for($type);

            if (! $class->isAbstract && ! $this->interfaceInferringContainer->has($class->name)) {
                $classAttrConverters = $this->attributeConvertersFor($class->attributes, $type);

                if ($classAttrConverters !== []) {
                    $typeMapper = new ConverterTypeMapperWrapper($type, $typeMapper, $classAttrConverters);
                }
            }
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
                );
            }

            // Interface/abstract without infer or registered constructors → passthrough
            if ($type instanceof InterfaceType || $class->isAbstract) {
                return new InterfacePassthroughTypeMapper($type);
            }

            return new ObjectTypeMapper(
                $class,
                $this->objectBuilderFactory->for($class),
            );
        }

        return match (true) {
            $type instanceof ScalarType => new ScalarTypeMapper($type),
            $type instanceof NullType => new NullTypeMapper(),
            $type instanceof MixedType => new MixedTypeMapper(),
            $type instanceof UndefinedObjectType => new UndefinedObjectTypeMapper(),
            $type instanceof UnionType => new UnionTypeMapper($type),
            $type instanceof ShapedArrayType => new ShapedArrayTypeMapper($type),
            $type instanceof ListType,
            $type instanceof NonEmptyListType => new ListTypeMapper($type),
            $type instanceof ArrayType,
            $type instanceof NonEmptyArrayType,
            $type instanceof IterableType => new ArrayTypeMapper($type),
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

    /**
     * Discover converter attributes from an Attributes collection and return
     * matching converter info for the given target type.
     *
     * @return array<int, array{key: string, paramType: Type, paramCount: int}>
     */
    public function attributeConvertersFor(Attributes $attributes, Type $targetType): array
    {
        if ($attributes->count() === 0 || $this->functionDefinitionRepository === null) {
            return [];
        }

        $converterAttributes = $attributes->filter(ConverterContainer::filterConverterAttributes(...));
        $matching = [];

        foreach ($converterAttributes->toArray() as $attrDef) {
            $callable = $attrDef->instantiate()->map(...);
            $definition = $this->functionDefinitionRepository->for($callable);

            if ($definition->returnType instanceof UnresolvableType) {
                continue;
            }

            $generics = $definition->returnType->inferGenericsFrom($targetType, new Generics());
            $resolved = $definition->assignGenerics($generics);

            $firstParameterType = $resolved->parameters->at(0)->type;
            $returnType = $resolved->returnType;

            if ($firstParameterType instanceof UnresolvableType || $returnType instanceof UnresolvableType) {
                continue;
            }

            if (! $returnType->matches($targetType)) {
                continue;
            }

            $callbackKey = self::attributeConverterKey($attrDef);
            $this->registerConstructorCallback($callbackKey, $callable);

            $matching[] = [
                'key' => $callbackKey,
                'paramType' => $firstParameterType,
                'paramCount' => $resolved->parameters->count(),
            ];
        }

        return $matching;
    }

    /**
     * Generate a deterministic callback key for an attribute converter.
     */
    public static function attributeConverterKey(AttributeDefinition $attrDef): string
    {
        $parts = implode('|', array_map('strval', $attrDef->reflectionParts));

        return 'attr_conv_' . hash('crc32', $attrDef->class->name . '|' . $parts . '|' . $attrDef->attributeIndex);
    }

    /**
     * Analyze converters against a target type and return those whose return
     * type matches. For each matching converter, the callback is registered as
     * a constructor callback and the first parameter type (with generics
     * resolved) is returned for runtime type checking.
     *
     * @return array<int, array{key: string, paramType: Type, paramCount: int}>
     */
    private function matchingConvertersFor(Type $type): array
    {
        $analyzedConverters = $this->getAnalyzedConverters();
        $matching = [];

        foreach ($analyzedConverters as $index => $entry) {
            $definition = $entry['definition'];

            if ($definition->returnType instanceof UnresolvableType) {
                continue;
            }

            // Apply generic inference: resolve template types against target
            $generics = $definition->returnType->inferGenericsFrom($type, new Generics());
            $resolved = $definition->assignGenerics($generics);

            $firstParameterType = $resolved->parameters->at(0)->type;
            $returnType = $resolved->returnType;

            if ($firstParameterType instanceof UnresolvableType || $returnType instanceof UnresolvableType) {
                continue;
            }

            if (!$returnType->matches($type)) {
                continue;
            }

            $callbackKey = 'converter_' . $index;
            $this->registerConstructorCallback($callbackKey, $entry['callable']);

            $matching[] = [
                'key' => $callbackKey,
                'paramType' => $firstParameterType,
                'paramCount' => $resolved->parameters->count(),
            ];
        }

        return $matching;
    }

    /**
     * @return list<array{callable: callable, definition: FunctionDefinition}>
     */
    private function getAnalyzedConverters(): array
    {
        if ($this->analyzedConverters !== null) {
            return $this->analyzedConverters;
        }

        $this->analyzedConverters = [];

        if ($this->functionDefinitionRepository === null) {
            return $this->analyzedConverters;
        }

        foreach ($this->converters as $converter) {
            $definition = $this->functionDefinitionRepository->for($converter);

            // Validate converter (same checks as ConverterContainer)
            if ($definition->parameters->count() === 0) {
                throw new ConverterHasNoParameter($definition);
            }

            if ($definition->parameters->count() > 2) {
                throw new ConverterHasTooManyParameters($definition);
            }

            if ($definition->parameters->count() > 1 && !$definition->parameters->at(1)->nativeType instanceof CallableType) {
                throw new ConverterHasInvalidCallableParameter($definition, $definition->parameters->at(1)->nativeType);
            }

            if ($definition->returnType instanceof UnresolvableType) {
                throw new ConverterHasInvalidReturnType($definition);
            }

            $this->analyzedConverters[] = [
                'callable' => $converter,
                'definition' => $definition,
            ];
        }

        return $this->analyzedConverters;
    }

    /**
     * Walk a type tree and register all FunctionObjectBuilder callbacks
     * without doing full compilation. Used when loading from cache.
     *
     * @param array<string, true> $visited Prevents infinite recursion for circular types
     */
    public function collectCallbacksForType(Type $type, array &$visited = []): void
    {
        $key = $type->toString();

        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        if ($type instanceof ObjectType) {
            $class = $this->classDefinitionRepository->for($type);

            // Register class-level attribute converter callbacks
            $this->collectAttributeConverterCallbacks($class->attributes);

            // Handle interface inferring: register infer callback and walk implementations
            if ($this->interfaceInferringContainer->has($class->name)) {
                $callbackKey = InterfaceTypeMapper::inferCallbackKey($class->name);
                $callback = $this->interfaceInferringContainer->inferCallbackFor($class->name);
                $this->registerConstructorCallback($callbackKey, $callback);

                try {
                    $implementations = $this->interfaceInferringContainer->classImplementationsFor($class->name);
                } catch (ObjectImplementationCallbackError) {
                    // Infer callback threw — no implementations to collect.
                    // The generated try/catch handles this at runtime.
                    return;
                }

                $implsKey = InterfaceTypeMapper::implementationsKey($class->name);
                $this->registerConstructorCallback($implsKey, $implementations);

                foreach ($implementations as $implType) {
                    $this->collectCallbacksForType($implType, $visited);
                }
                return;
            }

            // Interface/abstract without infer → collect registered constructor callbacks if any
            if ($type instanceof InterfaceType || $class->isAbstract) {
                if (!$this->hasRegisteredConstructorFor($type)) {
                    return;
                }
            }

            $builders = $this->objectBuilderFactory->for($class);

            foreach ($builders as $builder) {
                if ($builder instanceof FunctionObjectBuilder) {
                    $this->registerConstructorCallback($builder->callbackKey(), $builder->callback());
                }

                // Walk argument types and register attribute converter callbacks
                foreach ($builder->describeArguments() as $argument) {
                    $this->collectAttributeConverterCallbacks($argument->attributes());
                    $this->collectCallbacksForType($argument->type(), $visited);
                }
            }
        } elseif ($type instanceof UnionType) {
            foreach ($type->types() as $subType) {
                $this->collectCallbacksForType($subType, $visited);
            }
        } elseif ($type instanceof ShapedArrayType) {
            foreach ($type->elements as $element) {
                $this->collectAttributeConverterCallbacks($element->attributes());
                $this->collectCallbacksForType($element->type(), $visited);
            }
        } elseif ($type instanceof CompositeTraversableType) {
            $this->collectCallbacksForType($type->subType(), $visited);
        } elseif ($type instanceof ScalarType) {
            // Register canCast/cast callbacks for scalar types.
            // These are needed when allowScalarValueCasting is enabled;
            // registering them unconditionally is harmless since unused keys are ignored.
            $canCastKey = 'canCast_' . hash('crc32', $type->toString());
            $castKey = 'cast_' . hash('crc32', $type->toString());
            $this->registerConstructorCallback($canCastKey, $type->canCast(...));
            $this->registerConstructorCallback($castKey, $type->cast(...));
        }
    }

    /**
     * Register attribute converter callables as constructor callbacks.
     * Used when loading from cache to restore attribute converter callbacks.
     */
    private function collectAttributeConverterCallbacks(Attributes $attributes): void
    {
        if ($attributes->count() === 0) {
            return;
        }

        $converterAttributes = $attributes->filter(ConverterContainer::filterConverterAttributes(...));

        foreach ($converterAttributes->toArray() as $attrDef) {
            $callbackKey = self::attributeConverterKey($attrDef);
            $callable = $attrDef->instantiate()->map(...);
            $this->registerConstructorCallback($callbackKey, $callable);
        }
    }
}
