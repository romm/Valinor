<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Compiler\Native;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;

/** @internal */
final class AggregateNode extends Node
{
    public function __construct(
        /** @var list<Node> */
        private array $nodes,
    ) {}

    public function compile(Compiler $compiler): Compiler
    {
        foreach ($this->nodes as $node) {
            $compiler = $compiler->compile($node);
        }

        return $compiler;
    }
}
