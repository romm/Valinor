<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Compiler\Native;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;

use function addcslashes;
use function str_contains;

/** @internal */
final class ClassNameNode extends Node
{
    public function __construct(
        /** @var class-string */
        private string $className,
    ) {}

    public function compile(Compiler $compiler): Compiler
    {
        if (str_contains($this->className, '@anonymous')) {
            $escapedName = addcslashes($this->className, "\0\"\\$");

            return $compiler->write("(\$___cn = \"{$escapedName}\")");
        }

        return $compiler->write($this->className);
    }
}
