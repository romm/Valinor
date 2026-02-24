<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Tests\Integration\Mapping\Configurator;

use CuyZ\Valinor\Mapper\Configurator\ConvertKeyCase;
use CuyZ\Valinor\Mapper\Configurator\RestrictKeyCase;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RestrictKeyCaseTest extends IntegrationTestCase
{
    public function test_only_camel_case_allows_camel_case_keys(): void
    {
        $class = new class () {
            public string $someValue;
        };

        try {
            $result = $this->mapperBuilder()
                ->configureWith(RestrictKeyCase::OnlyCamelCase)
                ->mapper()
                ->map($class::class, ['someValue' => 'foo']);
        } catch (MappingError $error) {
            $this->mappingFail($error);
        }

        self::assertSame('foo', $result->someValue);
    }

    public function test_only_pascal_case_allows_pascal_case_keys(): void
    {
        $class = new class () {
            public string $someValue;
        };

        try {
            $result = $this->mapperBuilder()
                ->configureWith(
                    RestrictKeyCase::OnlyPascalCase,
                    ConvertKeyCase::ToCamelCase,
                )
                ->mapper()
                ->map($class::class, ['SomeValue' => 'foo']);
        } catch (MappingError $error) {
            $this->mappingFail($error);
        }

        self::assertSame('foo', $result->someValue);
    }

    public function test_only_snake_case_allows_snake_case_keys(): void
    {
        $class = new class () {
            public string $some_value;
        };

        try {
            $result = $this->mapperBuilder()
                ->configureWith(RestrictKeyCase::OnlySnakeCase)
                ->mapper()
                ->map($class::class, ['some_value' => 'foo']);
        } catch (MappingError $error) {
            $this->mappingFail($error);
        }

        self::assertSame('foo', $result->some_value);
    }

    public function test_only_kebab_case_allows_kebab_case_keys(): void
    {
        $class = new class () {
            public string $someValue;
        };

        try {
            $result = $this->mapperBuilder()
                ->configureWith(
                    RestrictKeyCase::OnlyKebabCase,
                    ConvertKeyCase::ToCamelCase,
                )
                ->mapper()
                ->map($class::class, ['some-value' => 'foo']);
        } catch (MappingError $error) {
            $this->mappingFail($error);
        }

        self::assertSame('foo', $result->someValue);
    }

    /**
     * @param non-empty-string $expectedMessage
     */
    #[DataProvider('invalid_case_data_provider')]
    public function test_invalid_key_case_throws_error(RestrictKeyCase $case, string $key, string $expectedMessage): void
    {
        $class = new class () {
            public string $someValue;
        };

        try {
            $this->mapperBuilder()
                ->configureWith($case)
                ->mapper()
                ->map($class::class, [$key => 'foo']);

            self::fail('No mapping error when one was expected');
        } catch (MappingError $exception) {
            self::assertMappingErrors($exception, [
                $key => $expectedMessage,
            ]);
        }
    }

    /**
     * @return iterable<string, array{RestrictKeyCase, string, non-empty-string}>
     */
    public static function invalid_case_data_provider(): iterable
    {
        yield 'camel case rejects snake_case' => [
            RestrictKeyCase::OnlyCamelCase,
            'some_value',
            '[invalid_key_case] Key must follow the camelCase format.',
        ];

        yield 'camel case rejects kebab-case' => [
            RestrictKeyCase::OnlyCamelCase,
            'some-value',
            '[invalid_key_case] Key must follow the camelCase format.',
        ];

        yield 'camel case rejects PascalCase' => [
            RestrictKeyCase::OnlyCamelCase,
            'SomeValue',
            '[invalid_key_case] Key must follow the camelCase format.',
        ];

        yield 'pascal case rejects camelCase' => [
            RestrictKeyCase::OnlyPascalCase,
            'someValue',
            '[invalid_key_case] Key must follow the PascalCase format.',
        ];

        yield 'pascal case rejects snake_case' => [
            RestrictKeyCase::OnlyPascalCase,
            'some_value',
            '[invalid_key_case] Key must follow the PascalCase format.',
        ];

        yield 'pascal case rejects kebab-case' => [
            RestrictKeyCase::OnlyPascalCase,
            'some-value',
            '[invalid_key_case] Key must follow the PascalCase format.',
        ];

        yield 'pascal case rejects trailing underscore' => [
            RestrictKeyCase::OnlyPascalCase,
            'SomeValue_',
            '[invalid_key_case] Key must follow the PascalCase format.',
        ];

        yield 'snake case rejects camelCase' => [
            RestrictKeyCase::OnlySnakeCase,
            'someValue',
            '[invalid_key_case] Key must follow the snake_case format.',
        ];

        yield 'snake case rejects kebab-case' => [
            RestrictKeyCase::OnlySnakeCase,
            'some-value',
            '[invalid_key_case] Key must follow the snake_case format.',
        ];

        yield 'snake case rejects PascalCase' => [
            RestrictKeyCase::OnlySnakeCase,
            'SomeValue',
            '[invalid_key_case] Key must follow the snake_case format.',
        ];

        yield 'kebab case rejects camelCase' => [
            RestrictKeyCase::OnlyKebabCase,
            'someValue',
            '[invalid_key_case] Key must follow the kebab-case format.',
        ];

        yield 'kebab case rejects snake_case' => [
            RestrictKeyCase::OnlyKebabCase,
            'some_value',
            '[invalid_key_case] Key must follow the kebab-case format.',
        ];

        yield 'kebab case rejects PascalCase' => [
            RestrictKeyCase::OnlyKebabCase,
            'SomeValue',
            '[invalid_key_case] Key must follow the kebab-case format.',
        ];
    }
}
