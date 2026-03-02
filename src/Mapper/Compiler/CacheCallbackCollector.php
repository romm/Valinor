<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Definition\Attributes;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\InterfaceTypeMapper;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Object\FunctionObjectBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\ConverterContainer;
use CuyZ\Valinor\Mapper\Tree\Builder\InterfaceInferringContainer;
use CuyZ\Valinor\Mapper\Tree\Exception\ObjectImplementationCallbackError;
use CuyZ\Valinor\Type\CompositeTraversableType;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\InterfaceType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\UnionType;

use function hash;

/**
 * Walks type trees and re-registers callbacks when loading from cache.
 * Since closures cannot be serialized, this collector ensures all runtime
 * callbacks are available after cache deserialization.
 *
 * @internal
 */
final class CacheCallbackCollector
{
    public function __construct(
        private ClassDefinitionRepository $classDefinitionRepository,
        private ObjectBuilderFactory $objectBuilderFactory,
        private InterfaceInferringContainer $interfaceInferringContainer,
        private ConverterAnalyzer $converterAnalyzer,
    ) {}

    /**
     * Walk a type tree and register all FunctionObjectBuilder callbacks
     * without doing full compilation. Used when loading from cache.
     *
     * @param array<string, true> $visited Prevents infinite recursion for circular types
     */
    public function collectCallbacksForType(
        Type $type,
        ConstructorCallbackRegistry $registry,
        array &$visited = []
    ): void {
        $key = $type->toString();

        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        if ($type instanceof ObjectType) {
            $class = $this->classDefinitionRepository->for($type);

            // Register class-level attribute converter callbacks
            $this->collectAttributeConverterCallbacks($class->attributes, $registry);

            // Handle interface inferring: register infer callback and walk implementations
            if ($this->interfaceInferringContainer->has($class->name)) {
                $callbackKey = InterfaceTypeMapper::inferCallbackKey($class->name);
                $callback = $this->interfaceInferringContainer->inferCallbackFor($class->name);
                $registry->register($callbackKey, $callback);

                try {
                    $implementations = $this->interfaceInferringContainer->classImplementationsFor($class->name);
                } catch (ObjectImplementationCallbackError) {
                    // Infer callback threw — no implementations to collect.
                    // The generated try/catch handles this at runtime.
                    return;
                }

                $implsKey = InterfaceTypeMapper::implementationsKey($class->name);
                $registry->register($implsKey, $implementations);

                foreach ($implementations as $implType) {
                    $this->collectCallbacksForType($implType, $registry, $visited);
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
                    $registry->register($builder->callbackKey(), $builder->callback());
                }

                // Walk argument types and register attribute converter callbacks
                foreach ($builder->describeArguments() as $argument) {
                    $this->collectAttributeConverterCallbacks($argument->attributes(), $registry);
                    $this->collectCallbacksForType($argument->type(), $registry, $visited);
                }
            }
        } elseif ($type instanceof UnionType) {
            foreach ($type->types() as $subType) {
                $this->collectCallbacksForType($subType, $registry, $visited);
            }
        } elseif ($type instanceof ShapedArrayType) {
            foreach ($type->elements as $element) {
                $this->collectAttributeConverterCallbacks($element->attributes(), $registry);
                $this->collectCallbacksForType($element->type(), $registry, $visited);
            }
        } elseif ($type instanceof CompositeTraversableType) {
            $this->collectCallbacksForType($type->subType(), $registry, $visited);
        } elseif ($type instanceof ScalarType) {
            // Register canCast/cast callbacks for scalar types.
            // These are needed when allowScalarValueCasting is enabled;
            // registering them unconditionally is harmless since unused keys are ignored.
            $canCastKey = 'canCast_' . hash('crc32', $type->toString());
            $castKey = 'cast_' . hash('crc32', $type->toString());
            $registry->register($canCastKey, $type->canCast(...));
            $registry->register($castKey, $type->cast(...));
        }
    }

    /**
     * Register attribute converter callables as constructor callbacks.
     * Used when loading from cache to restore attribute converter callbacks.
     */
    private function collectAttributeConverterCallbacks(Attributes $attributes, ConstructorCallbackRegistry $registry): void
    {
        if ($attributes->count() === 0) {
            return;
        }

        $converterAttributes = $attributes->filter(ConverterContainer::filterConverterAttributes(...));

        foreach ($converterAttributes->toArray() as $attrDef) {
            $callbackKey = ConverterAnalyzer::attributeConverterKey($attrDef);
            $callable = $attrDef->instantiate()->map(...);
            $registry->register($callbackKey, $callable);
        }
    }

    /**
     * Check if an object type has a registered constructor.
     */
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
