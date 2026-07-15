<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Compiler\Native;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;

use function is_array;
use function trim;

/** @internal */
final class IfNode extends Node
{
    public function __construct(
        private Node $condition,
        /** @var Node|list<Node> */
        private Node|array $then = [],
        /** @var Node|list<Node> */
        private Node|array $else = [],
    ) {}

    public function compile(Compiler $compiler): Compiler
    {
        $then = $this->then;
        $else = $this->else;

        if (! is_array($then)) {
            $then = [$then];
        }

        if (! is_array($else)) {
            $else = [$else];
        }

        $condition = $compiler->sub()->compile($this->condition)->code();
        $thenBody = $compiler->sub()->indent()->compile(...$then)->code();
        $closing = '}';

        if ($else !== []) {
            $elseBody = $compiler->sub()->indent()->compile(...$else)->code();

            if (trim($elseBody) !== '') {
                $closing = <<<PHP
                } else {
                $elseBody
                }
                PHP;
            }
        }

        return $compiler->write(
            <<<PHP
            if ($condition) {
            $thenBody
            $closing
            PHP,
        );
    }
}
