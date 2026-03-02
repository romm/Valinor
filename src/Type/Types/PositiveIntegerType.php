<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Type\Types;

use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Tree\Message\ErrorMessage;
use CuyZ\Valinor\Mapper\Tree\Message\MessageBuilder;
use CuyZ\Valinor\Type\IntegerType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Utility\IsSingleton;

use function assert;
use function filter_var;
use function is_bool;
use function is_int;
use function is_string;
use function ltrim;

/** @internal */
final class PositiveIntegerType implements IntegerType
{
    use IsSingleton;

    public function accepts(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    public function compiledAccept(ComplianceNode $node): ComplianceNode
    {
        return Node::functionCall('is_int', [$node])->and($node->isGreaterThan(Node::value(0)));
    }

    public function matches(Type $other): bool
    {
        if ($other instanceof UnionType) {
            return $other->isMatchedBy($this);
        }

        if ($other instanceof ArrayKeyType) {
            return $other->isMatchedBy($this);
        }

        return $other instanceof self
            || $other instanceof NativeIntegerType
            || $other instanceof ScalarConcreteType
            || $other instanceof MixedType;
    }

    public function inferGenericsFrom(Type $other, Generics $generics): Generics
    {
        return $generics;
    }

    public function canCast(mixed $value): bool
    {
        if (is_string($value) && $value !== '') {
            $value = ltrim($value, '0') ?: '0';
        }

        return ! is_bool($value)
            && filter_var($value, FILTER_VALIDATE_INT) !== false
            && $value > 0;
    }

    public function cast(mixed $value): int
    {
        assert($this->canCast($value));

        return (int)$value; // @phpstan-ignore-line
    }

    public function errorMessage(): ErrorMessage
    {
        return MessageBuilder::newError('Value {source_value} is not a valid positive integer.')
            ->withCode('invalid_positive_integer')
            ->build();
    }

    public function compiledCanCast(ComplianceNode $node): ComplianceNode
    {
        $trimmedValue = Node::ternary(
            Node::functionCall('is_string', [$node])->and($node->different(Node::value(''))),
            Node::ternary(
                Node::functionCall('ltrim', [$node, Node::value('0')])->different(Node::value('')),
                Node::functionCall('ltrim', [$node, Node::value('0')]),
                Node::value('0'),
            ),
            $node,
        );

        return Node::negate(Node::functionCall('is_bool', [$node]))
            ->and(
                Node::functionCall('filter_var', [$trimmedValue, Node::value(FILTER_VALIDATE_INT)])
                    ->different(Node::value(false))
            )
            ->and($node->isGreaterThan(Node::value(0)));
    }

    public function compiledCast(ComplianceNode $node): ComplianceNode
    {
        return $node->castTo($this);
    }

    public function nativeType(): NativeIntegerType
    {
        return NativeIntegerType::get();
    }

    public function toString(): string
    {
        return 'positive-int';
    }
}
