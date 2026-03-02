<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use ArrayObject;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Mapper\Tree\Message\NodeMessage;

/** @internal */
final class MappingContext
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $path = '*root*',
        /** @var ArrayObject<NodeMessage> */
        public readonly ArrayObject $messages = new ArrayObject(),
    ) {}

    public function sub(string $name): self
    {
        return new self($name, $this->path === '*root*' ? $name : "$this->path.$name", $this->messages);
    }

    public function isolate(): self
    {
        return new self($this->name, $this->path, new ArrayObject());
    }

    public function containsErrors(): bool
    {
        return $this->messages->count() > 0;
    }

    public function mergeFrom(self $other): void
    {
        foreach ($other->messages as $message) {
            $this->messages->append($message);
        }
    }

    public function addMessage(Message $message, string $type, string $sourceValue): void
    {
        $this->messages->append(new NodeMessage(
            message: $message,
            body: $message->body(),
            name: $this->name,
            path: $this->path,
            type: $type,
            expectedSignature: $type,
            sourceValue: $sourceValue,
        ));
    }
}
