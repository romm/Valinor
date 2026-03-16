<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use function hash;
use function preg_replace;
use function strtolower;

/**
 * Provides a shared method name generation helper for TypeMappers.
 *
 * All compiled TypeMappers need unique method names for the compiled mapper class.
 * This trait centralizes the slug generation and hash-based uniqueness logic.
 */
trait TypeMapperMethodName
{
    /**
     * Builds a unique method name for a compiled mapper method.
     *
     * @param non-empty-string $prefix The method prefix (e.g., 'map_object', 'convert_and_map')
     * @param string $typeString The type string to use for slug generation
     * @param string|null $hashInput Optional custom input for hash (defaults to $typeString).
     *                                Use this for disambiguation when the same type needs
     *                                different compiled methods (e.g., with/without key converters,
     *                                or with different attribute converters).
     * @return non-empty-string
     */
    protected static function buildMethodName(string $prefix, string $typeString, ?string $hashInput = null): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($typeString));

        return "{$prefix}_{$slug}_" . hash('crc32', $hashInput ?? $typeString);
    }
}
