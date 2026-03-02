<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use ArrayObject;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Mapper\Tree\Message\NodeMessage;
use CuyZ\Valinor\Type\FixedType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\EnumType;
use CuyZ\Valinor\Type\VacantType;

/** @internal */
final class MappingContext
{
    /** @var array<string, true> */
    private array $allowedSuperfluousKeys = [];

    /** @var array<string, string> */
    private array $nameMap = [];

    public function __construct(
        public readonly string $name = '',
        public readonly string $path = '*root*',
        /** @var ArrayObject<NodeMessage> */
        public readonly ArrayObject $messages = new ArrayObject(),
    ) {}

    public function sub(string $name): self
    {
        $originalName = $this->nameMap[$name] ?? $name;
        $ctx = new self($originalName, $this->path === '*root*' ? $originalName : "$this->path.$originalName", $this->messages);
        $ctx->allowedSuperfluousKeys = $this->allowedSuperfluousKeys;
        return $ctx;
    }

    /**
     * @param array<string, string> $nameMap Converted key → original key
     */
    public function setNameMap(array $nameMap): void
    {
        $this->nameMap = $nameMap;
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

    public function allowSuperfluousKeys(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->allowedSuperfluousKeys[$key] = true;
        }
    }

    public function isAllowedSuperfluousKey(string $key): bool
    {
        return isset($this->allowedSuperfluousKeys[$key]);
    }

    public function addMessage(Message $message, string $type, string $sourceValue, ?string $expectedSignature = null): void
    {
        $this->messages->append(new NodeMessage(
            message: $message,
            body: $message->body(),
            name: $this->name,
            path: $this->path,
            type: "`{$type}`",
            expectedSignature: $expectedSignature ?? "`{$type}`",
            sourceValue: $sourceValue,
        ));
    }

    /**
     * Compute the expected signature for a Type at compile time,
     * mirroring the runtime TypeDumper logic.
     */
    public static function expectedSignatureForType(Type $type): string
    {
        if ($type instanceof EnumType) {
            return $type->readableSignature();
        }

        if ($type instanceof FixedType || $type instanceof VacantType) {
            return $type->toString();
        }

        return '`' . $type->toString() . '`';
    }
}
