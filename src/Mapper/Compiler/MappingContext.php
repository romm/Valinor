<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use ArrayObject;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveTypeFromUnion;
use CuyZ\Valinor\Mapper\Tree\Exception\TooManyResolvedTypesFromUnion;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Mapper\Tree\Message\NodeMessage;
use CuyZ\Valinor\Type\FixedType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\EnumType;
use CuyZ\Valinor\Type\VacantType;
use CuyZ\Valinor\Utility\ValueDumper;

use function count;
use function krsort;
use function reset;
use function usort;

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

    /**
     * Resolve a union type at runtime. Mirrors the logic from UnionNodeBuilder.
     *
     * Each candidate has:
     * - result: the mapped value
     * - context: isolated MappingContext
     * - category: 'struct', 'scalar', or 'other'
     * - errorPriority: TypeHelper::typePriority for error grouping (object=3, array=2, scalar/null=1)
     * - scalarPriority: TypeHelper::scalarTypePriority for scalar resolution (int=4, float=3, string=2, bool=1)
     * - children: argument/element count for struct specificity
     *
     * @param list<array{result: mixed, context: self, category: string, errorPriority: int, scalarPriority: int, children: int}> $candidates
     */
    public function resolveUnion(array $candidates, mixed $source, string $unionType, string $expectedSignature): mixed
    {
        $valid = [];
        $structs = [];
        $scalars = [];
        $errors = [];

        foreach ($candidates as $candidate) {
            if ($candidate['context']->containsErrors()) {
                $errors[$candidate['errorPriority']][] = $candidate;
                continue;
            }

            $valid[] = $candidate;

            if ($candidate['category'] === 'struct') {
                $structs[] = $candidate;
            } elseif ($candidate['category'] === 'scalar') {
                $scalars[] = $candidate;
            }
        }

        if ($valid === []) {
            // No valid match: pick the error from the highest-priority type
            krsort($errors);

            if ($errors !== [] && count(reset($errors)) === 1) {
                // Single error from the highest-priority type: merge its errors
                $best = reset($errors)[0];
                $this->mergeFrom($best['context']);
                return $best['result'];
            }

            // Multiple errors or no priority winner: general union error
            if ($source === null) {
                $this->addMessage(
                    new CannotResolveTypeFromUnion(null),
                    $unionType,
                    '*missing*',
                    $expectedSignature,
                );
            } else {
                $this->addMessage(
                    new CannotResolveTypeFromUnion($source),
                    $unionType,
                    ValueDumper::dump($source),
                    $expectedSignature,
                );
            }

            return null;
        }

        if (count($valid) === 1) {
            return $valid[0]['result'];
        }

        // If there is only one scalar and one struct, the scalar has priority
        if (count($scalars) === 1 && count($structs) === 1) {
            return $scalars[0]['result'];
        }

        if ($structs !== []) {
            // Pick struct with most children (most specific)
            $childrenCount = [];
            foreach ($structs as $struct) {
                $childrenCount[$struct['children']][] = $struct;
            }
            krsort($childrenCount);
            $first = reset($childrenCount);

            if (count($first) === 1) {
                return $first[0]['result'];
            }
        } elseif ($scalars !== []) {
            // Sort by scalar type priority (int > float > string > bool)
            usort($scalars, static fn (array $a, array $b): int => $b['scalarPriority'] <=> $a['scalarPriority']);
            return $scalars[0]['result'];
        }

        // Too many resolved types: collision
        $this->addMessage(
            new TooManyResolvedTypesFromUnion(),
            $unionType,
            ValueDumper::dump($source),
            $expectedSignature,
        );

        return null;
    }
}
