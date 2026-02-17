# Common mapper configurators

This library provides a set of [mapper configurators] out-of-the-box that can be
used to apply common mapping behaviors:

[mapper configurators]: ./use-mapper-configurators.md

- [Restricting key case](#restricting-key-case)

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
