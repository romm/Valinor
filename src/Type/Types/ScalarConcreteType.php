<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Type\Types;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Tree\Message\ErrorMessage;
use CuyZ\Valinor\Mapper\Tree\Message\MessageBuilder;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Utility\IsSingleton;
use Stringable;

use function assert;
use function CuyZ\Valinor\Compiler\{call, castTo, logicalOr, ternary};
use function is_scalar;

/** @internal */
final class ScalarConcreteType implements ScalarType
{
    use IsSingleton;

    public function accepts(mixed $value): bool
    {
        return is_scalar($value);
    }

    public function compiledAccept(Node $node): Node
    {
        return call('is_scalar', [$node]);
    }

    public function matches(Type $other): bool
    {
        if ($other instanceof UnionType) {
            return (new UnionType(NativeIntegerType::get(), NativeFloatType::get(), NativeStringType::get(), NativeBooleanType::get()))->matches($other);
        }

        return $other instanceof self
            || $other instanceof MixedType;
    }

    public function inferGenericsFrom(Type $other, Generics $generics): Generics
    {
        return $generics;
    }

    public function compiledCanCast(Node $node): Node
    {
        return logicalOr(
            call('is_scalar', [$node]),
            $node->instanceOf(\Stringable::class),
        );
    }

    public function compiledCast(Node $node): Node
    {
        // If Stringable, cast to string; otherwise return as-is (already scalar)
        return ternary(
            $node->instanceOf(\Stringable::class),
            castTo(NativeStringType::get(), $node),
            $node,
        )->wrap();
    }

    public function canCast(mixed $value): bool
    {
        return is_scalar($value) || $value instanceof Stringable;
    }

    public function cast(mixed $value): bool|string|int|float
    {
        assert($this->canCast($value));

        if ($value instanceof Stringable) {
            return (string)$value;
        }

        return $value; // @phpstan-ignore return.type (must be scalar)
    }

    public function errorMessage(): ErrorMessage
    {
        return MessageBuilder::newError('Value {source_value} is not a valid scalar.')->build();
    }

    public function nativeType(): UnionType
    {
        return new UnionType(
            new NativeIntegerType(),
            new NativeFloatType(),
            new NativeStringType(),
            new NativeBooleanType(),
        );
    }

    public function toString(): string
    {
        return 'scalar';
    }
}
