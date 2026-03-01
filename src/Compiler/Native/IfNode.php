<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Compiler\Native;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;

use function is_array;

/** @internal */
final class IfNode extends Node
{
    public function __construct(
        private Node $condition,
        /** @var Node|non-empty-list<Node> */
        private Node|array $body,
    ) {}

    public function compile(Compiler $compiler): Compiler
    {
        $body = $this->body;

        if (! is_array($body)) {
            $body = [$body];
        }

        $condition = $compiler->sub()->compile($this->condition)->code();
        $body = $compiler->sub()->indent()->compile(...$body)->code();

        return $compiler->write(
            <<<PHP
            if ($condition) {
            $body
            }
            PHP,
        );
    }
}
