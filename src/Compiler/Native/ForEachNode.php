<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Compiler\Native;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;

use function is_array;

/** @internal */
final class ForEachNode extends Node
{
    public function __construct(
        private Node $value,
        /** @var non-empty-string */
        private string $key,
        /** @var non-empty-string */
        private string $item,
        /** @var Node|non-empty-list<Node> */
        private Node|array $body,
    ) {}

    public function compile(Compiler $compiler): Compiler
    {
        $value = $compiler->sub()->compile($this->value)->code();
        $body = $compiler->sub()->indent()->compile(...is_array($this->body) ? $this->body : [$this->body])->code();

        return $compiler->write(
            <<<PHP
            foreach ($value as $$this->key => $$this->item) {
            $body
            }
            PHP
        );
    }
}
