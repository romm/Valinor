<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Definition\Attributes;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Object\FunctionObjectBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\ConverterContainer;
use CuyZ\Valinor\Mapper\Tree\Builder\InterfaceInferringContainer;
use CuyZ\Valinor\Mapper\Tree\Exception\ObjectImplementationCallbackError;
use CuyZ\Valinor\Type\CompositeTraversableType;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\InterfaceType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\UnionType;

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
    ) {}

    /**
     * Walk a type tree and register all FunctionObjectBuilder callbacks
     * without doing full compilation. Used when loading from cache.
     *
     * @param array<string, true> $visited Prevents infinite recursion for circular types
     */
    public function collectCallbacksForType(
        Type $type,
        \Closure $register,
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
            $this->collectAttributeConverterCallbacks($class->attributes, $register);

            // Handle interface inferring: walk implementations for their callbacks
            if ($this->interfaceInferringContainer->has($class->name)) {
                try {
                    $implementations = $this->interfaceInferringContainer->classImplementationsFor($class->name);
                } catch (ObjectImplementationCallbackError) {
                    // Infer callback threw — no implementations to collect.
                    // The generated try/catch handles this at runtime.
                    return;
                }

                foreach ($implementations as $implType) {
                    $this->collectCallbacksForType($implType, $register, $visited);
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
                    $register($builder->callbackKey(), $builder->callback());
                }

                // Walk argument types and register attribute converter callbacks
                foreach ($builder->describeArguments() as $argument) {
                    $this->collectAttributeConverterCallbacks($argument->attributes(), $register);
                    $this->collectCallbacksForType($argument->type(), $register, $visited);
                }
            }
        } elseif ($type instanceof UnionType) {
            foreach ($type->types() as $subType) {
                $this->collectCallbacksForType($subType, $register, $visited);
            }
        } elseif ($type instanceof ShapedArrayType) {
            foreach ($type->elements as $element) {
                $this->collectAttributeConverterCallbacks($element->attributes(), $register);
                $this->collectCallbacksForType($element->type(), $register, $visited);
            }
        } elseif ($type instanceof CompositeTraversableType) {
            $this->collectCallbacksForType($type->subType(), $register, $visited);
        }
    }

    /**
     * Register attribute converter callables as constructor callbacks.
     * Used when loading from cache to restore attribute converter callbacks.
     */
    private function collectAttributeConverterCallbacks(Attributes $attributes, \Closure $register): void
    {
        if ($attributes->count() === 0) {
            return;
        }

        $converterAttributes = $attributes->filter(ConverterContainer::filterConverterAttributes(...));

        foreach ($converterAttributes->toArray() as $attrDef) {
            $callbackKey = ConverterAnalyzer::attributeConverterKey($attrDef);
            $callable = $attrDef->instantiate()->map(...);
            $register($callbackKey, $callable);
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
