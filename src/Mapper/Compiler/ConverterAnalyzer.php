<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Definition\AttributeDefinition;
use CuyZ\Valinor\Definition\Attributes;
use CuyZ\Valinor\Definition\FunctionDefinition;
use CuyZ\Valinor\Definition\Repository\FunctionDefinitionRepository;
use CuyZ\Valinor\Mapper\Tree\Builder\ConverterContainer;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasInvalidCallableParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasInvalidReturnType;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasNoParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasTooManyParameters;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\CallableType;
use CuyZ\Valinor\Type\Types\Generics;
use CuyZ\Valinor\Type\Types\UnresolvableType;

use function array_map;
use function hash;
use function implode;
use function strval;

/**
 * Analyzes and matches converters (both global and attribute-based) against target types.
 *
 * @internal
 */
final class ConverterAnalyzer
{
    /** @var list<array{callable: callable, definition: FunctionDefinition}>|null */
    private ?array $analyzedConverters = null;

    public function __construct(
        private ?FunctionDefinitionRepository $functionDefinitionRepository,
        /** @var list<callable> */
        private array $converters,
    ) {}

    /**
     * Analyze global converters against a target type and return those whose return
     * type matches. For each matching converter, the callback is registered as
     * a constructor callback and the first parameter type (with generics
     * resolved) is returned for runtime type checking.
     *
     * @return array<int, array{key: string, paramType: Type, paramCount: int}>
     */
    public function matchingConvertersFor(Type $type, ConstructorCallbackRegistry $registry): array
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
            $registry->register($callbackKey, $entry['callable']);

            $matching[] = [
                'key' => $callbackKey,
                'paramType' => $firstParameterType,
                'paramCount' => $resolved->parameters->count(),
            ];
        }

        return $matching;
    }

    /**
     * Discover converter attributes from an Attributes collection and return
     * matching converter info for the given target type.
     *
     * @return array<int, array{key: string, paramType: Type, paramCount: int}>
     */
    public function attributeConvertersFor(Attributes $attributes, Type $targetType, ConstructorCallbackRegistry $registry): array
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
            $registry->register($callbackKey, $callable);

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
     * Register all global converter callables as constructor callbacks.
     * Called during reset to ensure converters are available for cached mappers.
     */
    public function registerConverterCallbacks(ConstructorCallbackRegistry $registry): void
    {
        foreach ($this->converters as $index => $converter) {
            $registry->register('converter_' . $index, $converter);
        }
    }

    /**
     * Validate and cache all converter definitions.
     *
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
}
