<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Mapper\Tree\Message\NodeMessage;

use function array_pop;
use function end;
use function implode;

/** @internal */
final class TodoContext
{
    /** @var list<non-empty-string> */
    private array $path = [];

    /** @var list<NodeMessage> */
    private array $messages = [];

    public function sub(string $name): self
    {
        $self = clone $this;
        $self->path[] = $name;
        $self->messages = &$this->messages;

        return $self;
    }

    public function up(): void
    {
        array_pop($this->path);
    }

    public function addMessage(Message $message, string $type, string $sourceValue): void
    {
        $this->messages[] = new NodeMessage(
            $message,
            $message->body(),
            end($this->path),
            implode('.', $this->path),
            $type,
            $sourceValue,
        );
    }

    /**
     * @return list<NodeMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }
}
