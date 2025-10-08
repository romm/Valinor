<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Tests\Integration\Mapping\Converter;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasInvalidCallableParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasNoParameter;
use CuyZ\Valinor\Mapper\Tree\Exception\ConverterHasTooManyParameters;
use CuyZ\Valinor\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function iterator_to_array;

final class ValueConverterMappingTest extends IntegrationTestCase
{
    /**
     * @param array<int, list<callable>> $convertersByPriority
     */
    #[DataProvider('value_is_converted_properly_data_provider')]
    public function test_value_is_converted_properly(string $type, mixed $value, mixed $expectedResult, array $convertersByPriority): void
    {
        try {
            $builder = $this->mapperBuilder();

            foreach ($convertersByPriority as $priority => $converters) {
                foreach ($converters as $converter) {
                    $builder = $builder->registerConverter($converter, $priority);
                }
            }

            $result = $builder
                ->mapper()
                ->map($type, $value);

            self::assertSame($expectedResult, $result);
        } catch (MappingError $error) {
            $this->mappingFail($error);
        }
    }

    public static function value_is_converted_properly_data_provider(): iterable
    {
        yield 'string converted to uppercase' => [
            'type' => 'string',
            'value' => 'foo',
            'expectedResult' => 'FOO',
            'convertersByPriority' => [
                [
                    strtoupper(...),
                ],
            ],
        ];

        yield 'string with converter that calls next without value' => [
            'type' => 'string',
            'value' => 'foo',
            'expectedResult' => 'foo!',
            'convertersByPriority' => [
                [
                    fn (string $value, callable $next): string => $next() . '!', // @phpstan-ignore binaryOp.invalid (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                ],
            ],
        ];

        yield 'string with prioritized converters' => [
            'type' => 'string',
            'value' => 'foo',
            'expectedResult' => 'foo?!',
            'convertersByPriority' => [
                10 => [
                    fn (string $value, callable $next): string => $next($value . '!'), // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                ],
                50 => [
                    fn (string $value, callable $next): string => $next($value . '?'), // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                ],
            ],
        ];

        yield 'string with ignored converters' => [
            'type' => 'string',
            'value' => 'foo',
            'expectedResult' => 'foo!',
            'convertersByPriority' => [
                [
                    fn (int $value, callable $next): string => $next($value) . '?', // @phpstan-ignore binaryOp.invalid (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                    fn (int $value, callable $next): int => $next($value + 1), // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                    fn (string $value, callable $next): string => $next($value . '!'), // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                    fn (int $value, callable $next): int => $next($value + 2), // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                    fn (int $value, callable $next): string => $next($value) . '#', // @phpstan-ignore binaryOp.invalid (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                ],
            ],
        ];

        yield 'string with converter that does not call next' => [
            'type' => 'string',
            'value' => 'foo',
            'expectedResult' => 'foo!',
            'convertersByPriority' => [
                [
                    fn (string $value): string => $value . '!',
                    fn (string $value, callable $next): string => $next($value . '?'), // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                ],
            ],
        ];

        yield 'union type with converter matching one of the types' => [
            'type' => 'int|bool',
            'value' => 123,
            'expectedResult' => 124,
            'convertersByPriority' => [
                [
                    fn (int $value): int => $value + 1,
                ],
            ],
        ];

        yield 'generic array key' => [
            'type' => 'array<int, string>',
            'value' => [42 => 'foo', 1337 => 'bar'],
            'expectedResult' => [42 => 'foo!', 1337 => 'bar!'],
            'convertersByPriority' => [[
                /**
                 * @template T of array-key
                 * @param array<T, string> $value
                 * @return array<T, non-empty-string>
                 */
                fn (array $value) => array_map(fn ($v) => "$v!", $value), // @phpstan-ignore encapsedStringPart.nonString (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ]],
        ];

        yield 'generic array subtype' => [
            'type' => 'array<int, string>',
            'value' => [42 => 'foo', 1337 => 'bar'],
            'expectedResult' => [42 => 'foo!', 1337 => 'bar!'],
            'convertersByPriority' => [[
                /**
                 * @template T
                 * @param array<int, T> $value
                 * @return array<int, T>
                 */
                fn (array $value) => array_map(fn ($v) => "$v!", $value), // @phpstan-ignore encapsedStringPart.nonString (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ]],
        ];

        yield 'generic non-empty-array key' => [
            'type' => 'non-empty-array<int, string>',
            'value' => [42 => 'foo', 1337 => 'bar'],
            'expectedResult' => [42 => 'foo!', 1337 => 'bar!'],
            'convertersByPriority' => [[
                /**
                 * @template T of array-key
                 * @param non-empty-array<T, string> $value
                 * @return non-empty-array<T, non-empty-string>
                 */
                fn (array $value) => array_map(fn ($v) => "$v!", $value), // @phpstan-ignore encapsedStringPart.nonString (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ]],
        ];

        yield 'generic non-empty-array subtype' => [
            'type' => 'non-empty-array<int, string>',
            'value' => [42 => 'foo', 1337 => 'bar'],
            'expectedResult' => [42 => 'foo!', 1337 => 'bar!'],
            'convertersByPriority' => [[
                /**
                 * @template T
                 * @param non-empty-array<int, T> $value
                 * @return non-empty-array<int, T>
                 */
                fn (array $value) => array_map(fn ($v) => "$v!", $value), // @phpstan-ignore encapsedStringPart.nonString (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ]],
        ];

        yield 'generic list subtype' => [
            'type' => 'list<string>',
            'value' => ['foo', 'bar'],
            'expectedResult' => ['foo!', 'bar!'],
            'convertersByPriority' => [[
                /**
                 * @template T
                 * @param list<T> $value
                 * @return list<T>
                 */
                fn (array $value) => array_map(fn ($v) => "$v!", $value), // @phpstan-ignore encapsedStringPart.nonString (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ]],
        ];

        yield 'generic non-empty-list subtype' => [
            'type' => 'non-empty-list<string>',
            'value' => ['foo', 'bar'],
            'expectedResult' => ['foo!', 'bar!'],
            'convertersByPriority' => [[
                /**
                 * @template T
                 * @param non-empty-list<T> $value
                 * @return non-empty-list<T>
                 */
                fn (array $value) => array_map(fn ($v) => "$v!", $value), // @phpstan-ignore encapsedStringPart.nonString (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ]],
        ];

        yield 'generic iterable key' => [
            'type' => 'iterable<int, string>',
            'value' => [42 => 'foo', 1337 => 'bar'],
            'expectedResult' => [42 => 'foo!', 1337 => 'bar!'],
            'convertersByPriority' => [[
                /**
                 * @template T of array-key
                 * @param iterable<T, string> $value
                 * @return array<T, non-empty-string>
                 */
                fn (iterable $value) => array_map(fn ($v) => "$v!", iterator_to_array($value)), // @phpstan-ignore encapsedStringPart.nonString (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ]],
        ];

        yield 'generic iterable subtype' => [
            'type' => 'iterable<int, string>',
            'value' => [42 => 'foo', 1337 => 'bar'],
            'expectedResult' => [42 => 'foo!', 1337 => 'bar!'],
            'convertersByPriority' => [[
                /**
                 * @template T
                 * @param iterable<int, T> $value
                 * @return array<int, T>
                 */
                fn (iterable $value) => array_map(fn ($v) => "$v!", iterator_to_array($value)), // @phpstan-ignore encapsedStringPart.nonString (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ]],
        ];

        yield 'generic boolean' => [
            'type' => 'bool',
            'value' => false,
            'expectedResult' => true,
            'convertersByPriority' => [[
                /**
                 * @template T of true
                 * @return T
                 */
                fn (bool $value) => true,
            ]],
        ];

        yield 'generic boolean value' => [
            'type' => 'true',
            'value' => false,
            'expectedResult' => true,
            'convertersByPriority' => [[
                /**
                 * @template T of true
                 * @return T
                 */
                fn (bool $value) => true,
            ]],
        ];

        yield 'generic string' => [
            'type' => 'string',
            'value' => 'foo',
            'expectedResult' => 'foo!',
            'convertersByPriority' => [[
                /**
                 * @template T of string
                 * @return T|non-empty-string
                 */
                fn (string $value) => "$value!",
            ]],
        ];

        yield 'generic string value' => [
            'type' => '"foo"',
            'value' => 'foo',
            'expectedResult' => 'foo!',
            'convertersByPriority' => [[
                /**
                 * @template T of string
                 * @return T|non-empty-string
                 */
                fn (string $value) => "$value!",
            ]],
        ];

        yield 'generic non-empty-string' => [
            'type' => 'non-empty-string',
            'value' => 'foo',
            'expectedResult' => 'foo!',
            'convertersByPriority' => [[
                /**
                 * @template T of string
                 * @return T
                 */
                fn (string $value) => "$value!",
            ]],
        ];
    }

    public function test_converter_with_no_priority_has_priority_0_by_default(): void
    {
        $result = $this->mapperBuilder()
            ->registerConverter(fn (string $value, callable $next): string => $next($value . '!'), -1) // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ->registerConverter(fn (string $value, callable $next): string => $next($value . '?')) // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ->registerConverter(fn (string $value, callable $next): string => $next($value . '#'), 1) // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
            ->mapper()
            ->map('string', 'foo');

        self::assertSame('foo#?!', $result);
    }

    public function test_converters_are_called_only_once_for_interface(): void
    {
        $class = new class () implements SomeInterfaceForClassInferring {
            public int $value;
        };

        $result = $this->mapperBuilder()
            ->infer(SomeInterfaceForClassInferring::class, fn () => $class::class)
            ->registerConverter(
                /**
                 * @todo
                 * @template T of SomeInterfaceForClassInferring
                 * @return T
                 */
                function (int $value, callable $next): SomeInterfaceForClassInferring {
                    $value++;

                    /** @var SomeInterfaceForClassInferring */
                    return $next($value);
                }
            )
            ->mapper()
            ->map(SomeInterfaceForClassInferring::class, 123);

        self::assertSame(124, $result->value); // @phpstan-ignore property.notFound
    }

    public function test_converter_is_stopped_if_mapping_error_occurs(): void
    {
        try {
            $this->mapperBuilder()
                ->registerConverter(fn (string $value, callable $next): string => $next(42)) // @phpstan-ignore return.type (we cannot set closure parameters / see https://github.com/phpstan/phpstan/issues/3770)
                ->mapper()
                ->map('string', 'foo');
        } catch (MappingError $exception) {
            self::assertMappingErrors($exception, [
                '*root*' => '[invalid_string] Value 42 is not a valid string.',
            ]);
        }
    }

    public function test_converter_with_no_parameter_throws_exception(): void
    {
        $this->expectException(ConverterHasNoParameter::class);
        $this->expectExceptionMessageMatches('/The value converter `.*` has no parameter to convert the value to, a typed parameter is required\./');

        $this->mapperBuilder()
            ->registerConverter(fn () => 'bar')
            ->mapper()
            ->map('string', 'foo');
    }

    public function test_converter_with_too_many_parameters_throws_exception(): void
    {
        $this->expectException(ConverterHasTooManyParameters::class);
        $this->expectExceptionMessageMatches('/Converter must have at most 2 parameters, 3 given for `.*`\./');

        $this->mapperBuilder()
            ->registerConverter(fn (string $foo, callable $next, int $bar) => 'bar')
            ->mapper()
            ->map('string', 'foo');
    }

    public function test_converter_with_invalid_callable_parameter_throws_exception(): void
    {
        $this->expectException(ConverterHasInvalidCallableParameter::class);
        $this->expectExceptionMessageMatches('/Converter\'s second parameter must be a callable, `int` given for `.*`\./');

        $this->mapperBuilder()
            ->registerConverter(fn (string $foo, int $next) => 'bar')
            ->mapper()
            ->map('string', 'foo');
    }

    public function test_converter_returning_invalid_value_makes_mapping_fail(): void
    {
        try {
            $this->mapperBuilder()
                ->registerConverter(
                    /** @return non-empty-string */
                    fn (string $value) => ''
                )
                ->mapper()
                ->map('non-empty-string', 'foo');
        } catch (MappingError $exception) {
            self::assertMappingErrors($exception, [
                '*root*' => "[invalid_non_empty_string] Value '' is not a valid non-empty string.",
            ]);
        }
    }
}

interface SomeInterfaceForClassInferring {}
