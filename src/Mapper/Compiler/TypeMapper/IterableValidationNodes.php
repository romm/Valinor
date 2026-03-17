<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Type\Type;

use function CuyZ\Valinor\Compiler\{call, dumpValue, if_, negate, return_, value, variable};

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
                then: variable('source')->assign(value([]))->asStatement(),
            );
        } else {
            $nodes[] = if_(
                condition: variable('source')->equals(value(null)),
                then: [
                    new AddMessageNode(variable('context'), new SourceMustBeIterable(null), $type->toString(), value('*missing*'), $dumpedType),
                    return_(value(null)),
                ],
            );
        }

        $nodes[] = if_(
            condition: negate(call('is_iterable', [variable('source')])),
            then: [
                new AddMessageNode(variable('context'), new SourceMustBeIterable('value'), $type->toString(), dumpValue(variable('source')), $dumpedType),
                return_(value(null)),
            ],
        );

        return $nodes;
    }
}
