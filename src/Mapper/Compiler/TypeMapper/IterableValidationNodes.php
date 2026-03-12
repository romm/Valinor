<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Utility\ValueDumper;

use function CuyZ\Valinor\Compiler\{call, className, if_, negate, return_, value, variable};

/**
 * @internal
 * Generates common null-check and iterable-validation nodes for array-like mappers.
 */
final class IterableValidationNodes
{
    /**
     * @return list<Node> Nodes for null handling and iterable validation
     */
    public static function build(
        Settings $settings,
        Type $type,
        string|null $dumpedType = null,
    ): array {
        $nodes = [];

        if ($settings->allowUndefinedValues) {
            $nodes[] = if_(
                condition: variable('source')->equals(value(null)),
                body: variable('source')->assign(value([]))->asStatement(),
            );
        } else {
            // Null check with "missing" error body
            $messageArgs = [
                new MessageNode(new SourceMustBeIterable(null)),
                value($type->toString()),
                value('*missing*'),
            ];

            if ($dumpedType !== null) {
                $messageArgs[] = value($dumpedType);
            }

            $nodes[] = if_(
                condition: variable('source')->equals(value(null)),
                body: [
                    variable('context')->callMethod('addMessage', $messageArgs)->asStatement(),
                    return_(value(null)),
                ],
            );
        }

        // Non-iterable check with value error body (source is non-null here)
        $messageArgs = [
            new MessageNode(new SourceMustBeIterable('value')),
            value($type->toString()),
            className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
        ];

        if ($dumpedType !== null) {
            $messageArgs[] = value($dumpedType);
        }

        $nodes[] = if_(
            condition: negate(call('is_iterable', [variable('source')])),
            body: [
                variable('context')->callMethod('addMessage', $messageArgs)->asStatement(),
                return_(value(null)),
            ],
        );

        return $nodes;
    }
}
