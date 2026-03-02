<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Definition\Repository\FunctionDefinitionRepository;
use CuyZ\Valinor\Mapper\Tree\Exception\KeyConverterHasInvalidStringParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\KeyConverterHasNoParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\KeyConverterHasTooManyParameters;
use CuyZ\Valinor\Type\StringType;

/**
 * Validates and registers key converters for array key transformation.
 *
 * @internal
 */
final class KeyConverterHandler
{
    public function __construct(
        private ?FunctionDefinitionRepository $functionDefinitionRepository,
        /** @var list<callable(string): string> */
        private array $keyConverters,
    ) {}

    /**
     * Check if any key converters are registered.
     */
    public function hasKeyConverters(): bool
    {
        return $this->keyConverters !== [];
    }

    /**
     * Validate all key converters, returning their indices.
     *
     * @return list<int> Converter indices
     */
    public function keyConverterIndices(): array
    {
        $indices = [];

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

            $indices[] = $index;
        }

        return $indices;
    }
}
