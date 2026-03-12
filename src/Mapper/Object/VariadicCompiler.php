<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\Parameters;

use function CuyZ\Valinor\Compiler\{array_, call, value, variable};

/** @internal */
final class VariadicCompiler
{
    /**
     * Compiles variadic parameter handling logic.
     *
     * If the parameters contain a variadic parameter, this method generates
     * code to flatten the arguments array: non-variadic args are wrapped in
     * single-element arrays, the variadic array is unwrapped with array_values(),
     * then all parts are merged and assigned to $__flatArgs.
     *
     * @return list<Node>|null Returns nodes for variadic handling, or null if no variadic parameter exists
     */
    public static function compileVariadicArgs(
        Parameters $parameters,
        Node $values,
    ): ?array {
        // Check for variadic parameters
        $variadicName = null;

        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic) {
                $variadicName = $parameter->name;

                break;
            }
        }

        if ($variadicName === null) {
            return null;
        }

        // Flatten variadic: build positional args list, then spread
        $nonVariadicNames = [];

        foreach ($parameters as $parameter) {
            if (! $parameter->isVariadic) {
                $nonVariadicNames[] = $parameter->name;
            }
        }

        $flatParts = [];

        foreach ($nonVariadicNames as $name) {
            $flatParts[] = array_([$values->key(value($name))]);
        }

        $flatParts[] = call('array_values', [$values->key(value($variadicName))]);

        return [
            variable('__flatArgs')->assign(
                count($flatParts) === 1 ? $flatParts[0] : call('array_merge', $flatParts),
            )->asStatement(),
        ];
    }
}
