<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree\Exception;

use CuyZ\Valinor\Mapper\Tree\Shell;
use LogicException;

/** @internal */
final class CannotMapToPermissiveType extends LogicException
{
    public function __construct(Shell $shell)
    {
        $type = $shell->type->toString();

        parent::__construct(
            "Type `$type` at path `{$shell->path}` is not allowed in strict mode. " .
            "In case `$type` is really needed, the `allowPermissiveTypes` setting can be used.",
        );
    }

    // @todo this is awful, change that.
    public static function forType(string $type, string $path = '*root*'): self
    {
        $self = new \ReflectionClass(self::class);
        $instance = $self->newInstanceWithoutConstructor();

        // Set the message via parent LogicException constructor workaround
        $exception = new LogicException(
            "Type `$type` at path `$path` is not allowed in strict mode. " .
            "In case `$type` is really needed, the `allowPermissiveTypes` setting can be used.",
        );

        // Copy message and code from constructed exception
        $messageProp = new \ReflectionProperty(\Exception::class, 'message');
        $messageProp->setValue($instance, $exception->getMessage());

        return $instance;
    }
}
