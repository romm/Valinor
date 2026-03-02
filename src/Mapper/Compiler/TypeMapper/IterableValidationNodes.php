<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Utility\ValueDumper;

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
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: Node::variable('source')->assign(Node::value([]))->asExpression(),
            );
        } else {
            // Null check with "missing" error body
            $messageArgs = [
                new MessageNode(new SourceMustBeIterable(null)),
                Node::value($type->toString()),
                Node::value('*missing*'),
            ];

            if ($dumpedType !== null) {
                $messageArgs[] = Node::value($dumpedType);
            }

            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: [
                    Node::variable('context')->callMethod('addMessage', $messageArgs)->asExpression(),
                    Node::return(Node::value(null)),
                ],
            );
        }

        // Non-iterable check with value error body (source is non-null here)
        $messageArgs = [
            new MessageNode(new SourceMustBeIterable('value')),
            Node::value($type->toString()),
            Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
        ];

        if ($dumpedType !== null) {
            $messageArgs[] = Node::value($dumpedType);
        }

        $nodes[] = Node::if(
            condition: Node::negate(Node::functionCall('is_iterable', [Node::variable('source')])),
            body: [
                Node::variable('context')->callMethod('addMessage', $messageArgs)->asExpression(),
                Node::return(Node::value(null)),
            ],
        );

        return $nodes;
    }
}
