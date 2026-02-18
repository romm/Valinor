<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Configurator;

use CuyZ\Valinor\MapperBuilder;

use function lcfirst;
use function ltrim;
use function preg_replace;
use function str_replace;
use function strtolower;
use function ucwords;

/**
 * Converts the keys of input data before mapping them to object properties or
 * shaped array keys. This allows accepting data with a different naming
 * convention than the one used in the PHP codebase.
 *
 * A typical use case is mapping a JSON API payload that uses `snake_case` keys
 * to PHP objects that follow the `camelCase` convention:
 *
 * ```
 * use CuyZ\Valinor\MapperBuilder;
 * use CuyZ\Valinor\Mapper\Configurator\ConvertKeyCase;
 *
 * $user = (new MapperBuilder())
 *     ->configureWith(ConvertKeyCase::ToCamelCase)
 *     ->mapper()
 *     ->map(User::class, [
 *         'first_name' => 'John', // mapped to `$firstName`
 *         'last_name' => 'Doe',   // mapped to `$lastName`
 *     ]);
 * ```
 *
 * The reverse conversion is also available:
 *
 * ```
 * use CuyZ\Valinor\MapperBuilder;
 * use CuyZ\Valinor\Mapper\Configurator\ConvertKeyCase;
 *
 * $user = (new MapperBuilder())
 *     ->configureWith(ConvertKeyCase::ToSnakeCase)
 *     ->mapper()
 *     ->map(User::class, [
 *         'firstName' => 'John', // mapped to `$first_name`
 *         'lastName' => 'Doe',   // mapped to `$last_name`
 *     ]);
 * ```
 *
 * This configurator can be combined with {@see RestrictKeyCase} to both
 * validate and convert keys in a single step. When doing so, `RestrictKeyCase`
 * must be registered *before* `ConvertKeyCase` so that the validation runs on
 * the original input keys:
 *
 * ```
 * use CuyZ\Valinor\MapperBuilder;
 * use CuyZ\Valinor\Mapper\Configurator\ConvertKeyCase;
 * use CuyZ\Valinor\Mapper\Configurator\RestrictKeyCase;
 *
 * $user = (new MapperBuilder())
 *     ->configureWith(
 *         RestrictKeyCase::OnlySnakeCase,
 *         ConvertKeyCase::ToCamelCase,
 *     )
 *     ->mapper()
 *     ->map(User::class, [
 *         'first_name' => 'John',
 *         'last_name' => 'Doe',
 *     ]);
 * ```
 *
 * @api
 */
enum ConvertKeyCase implements MapperBuilderConfigurator
{
    case ToCamelCase; // toCamelCase
    case ToSnakeCase; // to_snake_case

    public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
    {
        return $builder->registerKeyConverter(
            match ($this) {
                self::ToCamelCase => self::convertToCamelCase(...),
                self::ToSnakeCase => self::convertToSnakeCase(...),
            }
        );
    }

    /** @pure */
    private static function convertToCamelCase(string $key): string
    {
        return lcfirst(str_replace(['_', '-'], '', ucwords($key, '_-')));
    }

    /** @pure */
    private static function convertToSnakeCase(string $key): string
    {
        return ltrim(strtolower((string)preg_replace('/[A-Z]/', '_$0', str_replace('-', '_', $key))), '_');
    }
}
