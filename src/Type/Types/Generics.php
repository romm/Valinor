<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Type\Types;

use CuyZ\Valinor\Type\Type;
use RuntimeException;

/** @internal */
final class Generics
{
    public function __construct(
        /** @var array<non-empty-string, Type> */
        public readonly array $items = [],
    ) {}

    public function with(GenericType $generic, Type $type): self
    {
        $name = $generic->symbol;

        if (isset($this->items[$name])) {
            $other = $this->items[$name];

            if ($other->matches($type)) {
                $type = $other;
            } elseif (! $type->matches($other)) {
                throw new RuntimeException('@todo'); // @todo
            }
        }

        return new self([...$this->items, ...[$name => $type]]);
    }

    /**
     * @param non-empty-list<Type> $types
     * @param non-empty-list<Type> $otherTypes
     */
    public function todo(array $types, array $otherTypes): self
    {
        $generics = $this;

        foreach ($types as $type) {
            foreach ($otherTypes as $otherType) {
                $generics = $type->inferGenericsFrom($otherType, $generics);
            }
        }

        return $generics;
    }
}
