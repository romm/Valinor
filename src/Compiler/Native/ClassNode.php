<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Compiler\Native;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;

use function addcslashes;
use function str_contains;

/** @internal */
final class ClassNode extends Node
{
    public function __construct(
        /** @var class-string */
        private string $name,
    ) {}

    /**
     * @param non-empty-string $method
     * @param array<Node> $arguments
     */
    public function callStaticMethod(
        string $method,
        array $arguments = [],
    ): Node {
        return new StaticMethodCallNode($this, $method, $arguments);
    }

    public function compile(Compiler $compiler): Compiler
    {
        if (str_contains($this->name, '@anonymous')) {
            $escapedName = addcslashes($this->name, "\0\"\\$");

            return $compiler->write("(\$___cn = \"{$escapedName}\")");
        }

        return $compiler->write($this->name);
    }
}
