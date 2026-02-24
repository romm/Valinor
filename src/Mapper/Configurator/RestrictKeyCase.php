<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Configurator;

use CuyZ\Valinor\Mapper\Tree\Message\MessageBuilder;
use CuyZ\Valinor\MapperBuilder;

use function preg_match;

/**
 * Restricts which key case is accepted when mapping input data to objects or
 * shaped array. If a key does not match the expected case, a mapping error will
 * be raised.
 *
 * This is useful, for instance, to enforce a consistent naming convention
 * across an API's input to ensure that a JSON payload only contains
 * `camelCase`, `snake_case` or `kebab-case` keys.
 *
 * ```
 * use CuyZ\Valinor\MapperBuilder;
 * use CuyZ\Valinor\Mapper\Configurator\RestrictKeyCase;
 *
 * // Enforce that all input keys are in camelCase
 * $user = (new MapperBuilder())
 *     ->configureWith(RestrictKeyCase::OnlyCamelCase)
 *     ->mapper()
 *     ->map(User::class, [
 *         'firstName' => 'John', // Ok
 *         'last_name' => 'Doe',  // Error
 *     ]);
 *
 * // Enforce that all input keys are in snake_case
 * $user = (new MapperBuilder())
 *     ->configureWith(RestrictKeyCase::OnlySnakeCase)
 *     ->mapper()
 *     ->map(User::class, [
 *         'first_name' => 'John', // Ok
 *         'lastName' => 'Doe',    // Error
 *     ]);
 * ```
 *
 * This configurator can be combined with {@see ConvertKeyCase} to both validate
 * and convert keys in a single step. When doing so, `RestrictKeyCase` must be
 * registered *before* `ConvertKeyCase` so that the validation runs on the
 * original input keys:
 *
 * ```
 * use CuyZ\Valinor\MapperBuilder;
 * use CuyZ\Valinor\Mapper\Configurator\ConvertKeyCase;
 * use CuyZ\Valinor\Mapper\Configurator\RestrictKeyCase;
 *
 * // Accept only snake_case keys and convert them to camelCase for mapping
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
enum RestrictKeyCase implements MapperBuilderConfigurator
{
    case OnlyCamelCase;  // onlyCamelCase
    case OnlyPascalCase; // OnlyPascalCase
    case OnlySnakeCase;  // only_snake_case
    case OnlyKebabCase;  // only-kebab-case

    public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
    {
        return $builder->registerKeyConverter(
            match ($this) {
                self::OnlyCamelCase => self::onlyCamelCase(...),
                self::OnlyPascalCase => self::onlyPascalCase(...),
                self::OnlySnakeCase => self::onlySnakeCase(...),
                self::OnlyKebabCase => self::onlyKebabCase(...),
            }
        );
    }

    /** @pure */
    private static function onlyCamelCase(string $key): string
    {
        if (preg_match('/^[a-z][a-zA-Z0-9]*$/', $key) === 0) {
            throw MessageBuilder::newError('Key must follow the camelCase format.')->withCode('invalid_key_case')->build();
        }

        return $key;
    }

    /** @pure */
    private static function onlyPascalCase(string $key): string
    {
        if (preg_match('/^[A-Z][a-zA-Z0-9]*$/', $key) === 0) {
            throw MessageBuilder::newError('Key must follow the PascalCase format.')->withCode('invalid_key_case')->build();
        }

        return $key;
    }

    /** @pure */
    private static function onlySnakeCase(string $key): string
    {
        if (preg_match('/^[a-z0-9_]*$/', $key) === 0) {
            throw MessageBuilder::newError('Key must follow the snake_case format.')->withCode('invalid_key_case')->build();
        }

        return $key;
    }

    /** @pure */
    private static function onlyKebabCase(string $key): string
    {
        if (preg_match('/^[a-z0-9-]*$/', $key) === 0) {
            throw MessageBuilder::newError('Key must follow the kebab-case format.')->withCode('invalid_key_case')->build();
        }

        return $key;
    }
}
