<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree\Exception;

use LogicException;

/** @internal */
final class CannotMapToPermissiveType extends LogicException
{
    public function __construct(string $type, string $path = '*root*')
    {
        parent::__construct(
            "Type `$type` at path `$path` is not allowed in strict mode. " .
            "In case `$type` is really needed, the `allowPermissiveTypes` setting can be used.",
        );
    }
}
