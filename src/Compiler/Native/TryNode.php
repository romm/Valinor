<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Compiler\Native;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;
use LogicException;
use Throwable;

/** @internal */
final class TryNode extends Node
{
    /** @var non-empty-list<Node> */
    private array $body;

    /** @var list<array{exception: class-string<Throwable>, body: list<Node>}> */
    private array $catch = [];

    public function __construct(Node ...$body)
    {
        $this->body = $body;
    }

    /**
     * @param class-string<Throwable> $exception
     */
    public function catches(string $exception, Node...$body): self
    {
        $self = clone $this;
        $self->catch[] = ['exception' => $exception, 'body' => $body];

        return $self;
    }

    public function compile(Compiler $compiler): Compiler
    {
        if ($this->catch === []) {
            throw new LogicException('At least one catch statement must be configured');
        }

        $compiler = $compiler
            ->write('try {' . PHP_EOL)
            ->write($compiler->sub()->indent()->compile(...$this->body)->code() . PHP_EOL)
            ->write('}');

        foreach ($this->catch as $catch) {
            $compiler = $compiler
                ->write(" catch ({$catch['exception']} \$exception) {" . PHP_EOL)
                ->write($compiler->sub()->indent()->compile(...$catch['body'])->code() . PHP_EOL)
                ->write('}');
        }

        return $compiler;
    }
}
