<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Compiler\Native;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;

use function is_array;

/** @internal */
final class IfNode extends Node
{
    /** @var Node|non-empty-list<Node>|null */
    private Node|array|null $else = null;

    public function __construct(
        private Node $condition,
        /** @var Node|non-empty-list<Node> */
        private Node|array $body,
    ) {}

    public function else(Node|array $else): self
    {
        $clone = clone $this;
        $clone->else = $else;

        return $clone;
    }

    public function compile(Compiler $compiler): Compiler
    {
        $body = $this->body;

        if (! is_array($body)) {
            $body = [$body];
        }

        $condition = $compiler->sub()->compile($this->condition)->code();
        $body = $compiler->sub()->indent()->compile(...$body)->code();
        $else = '';

        if ($this->else !== null) {
            $else = $this->else;

            if (! is_array($else)) {
                $else = [$else];
            }

            $elseBody = $compiler->sub()->indent()->compile(...$else)->code();

            $else = <<<PHP
            else {
            $elseBody
            }
            PHP;
        }

        return $compiler->write(
            <<<PHP
            if ($condition) {
            $body
            } $else
            PHP,
        );
    }
}
