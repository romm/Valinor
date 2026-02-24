# Common mapper configurators

This library provides a set of [mapper configurators] out-of-the-box that can be
used to apply common mapping behaviors:

[mapper configurators]: ./use-mapper-configurators.md

- [Restricting key case](#restricting-key-case)
- [Converting key case](#converting-key-case)

## Restricting key case

The `RestrictKeyCase` configurator restricts which key case is accepted when
mapping input data to objects or shaped array. If a key does not match the
expected case, a mapping error will be raised.

This is useful, for instance, to enforce a consistent naming convention across
an API's input to ensure that a JSON payload only contains `camelCase`,
`snake_case` or `kebab-case` keys.

Available cases:

| Case                              | Example       |
|-----------------------------------|---------------|
| `RestrictKeyCase::OnlyCamelCase`  | `firstName`   |
| `RestrictKeyCase::OnlyPascalCase` | `FirstName`   |
| `RestrictKeyCase::OnlySnakeCase`  | `first_name`  |
| `RestrictKeyCase::OnlyKebabCase`  | `first-name`  |

```php
$user = (new \CuyZ\Valinor\MapperBuilder())
    ->configureWith(\CuyZ\Valinor\Mapper\Configurator\RestrictKeyCase::OnlyCamelCase)
    ->mapper()
    ->map(\My\App\User::class, [
        'firstName' => 'John', // Ok
        'last_name' => 'Doe',  // Error
    ]);
```

## Converting key case

The `ConvertKeyCase` configurator converts the keys of input data before mapping
them to object properties or shaped array keys. This allows accepting data with
a different naming convention than the one used in the PHP codebase.

A typical use case is mapping a JSON API payload that uses `snake_case` keys to
PHP objects that follow the `camelCase` convention:

| Case                          | Conversion                  |
|-------------------------------|-----------------------------|
| `ConvertKeyCase::ToCamelCase` | `first_name` → `firstName`  |
| `ConvertKeyCase::ToSnakeCase` | `firstName` → `first_name`  |

```php
$user = (new \CuyZ\Valinor\MapperBuilder())
    ->configureWith(
        \CuyZ\Valinor\Mapper\Configurator\ConvertKeyCase::ToCamelCase
    )
    ->mapper()
    ->map(\My\App\User::class, [
        'first_name' => 'John', // mapped to `$firstName`
        'last_name' => 'Doe',   // mapped to `$lastName`
    ]);
```

This configurator can be combined with `RestrictKeyCase` to both validate and
convert keys in a single step. When doing so, `RestrictKeyCase` must be
registered *before* `ConvertKeyCase` so that the validation runs on the original
input keys:

```php
$user = (new \CuyZ\Valinor\MapperBuilder())
    ->configureWith(
        \CuyZ\Valinor\Mapper\Configurator\RestrictKeyCase::OnlySnakeCase,
        \CuyZ\Valinor\Mapper\Configurator\ConvertKeyCase::ToCamelCase,
    )
    ->mapper()
    ->map(\My\App\User::class, [
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);
```
