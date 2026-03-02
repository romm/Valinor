<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use Exception;

use function array_merge;

/**
 * Utility for generating key converter application nodes.
 *
 * @internal
 */
final class KeyConverterNodes
{
    /**
     * Builds nodes for applying key converters with error handling.
     *
     * Generates code that:
     * 1. Iterates over source array
     * 2. Applies all registered key converters to each key
     * 3. Catches and filters exceptions during conversion
     * 4. Builds a convertedSource array and nameMap
     * 5. Replaces source and sets nameMap on context
     * 6. Returns early if errors occurred
     *
     * @return list<Node> Nodes for applying key converters with error handling
     */
    public static function build(
        TypeMapperFactory $factory,
        bool $wrapInArrayCheck = false,
    ): array {
        $keyConverterKeys = $factory->keyConverterKeys();

        // Build converter chain: apply each converter to the key
        $keyVarNode = Node::variable('ck');
        $converterNodes = [];

        foreach ($keyConverterKeys as $kcKey) {
            $converterNodes[] = $keyVarNode->assign(
                Node::this()->access('constructorCallbacks')->key(Node::value($kcKey))->call([$keyVarNode]),
            )->asExpression();
        }

        $tryBody = array_merge($converterNodes, [
            Node::variable('convertedSource')->key($keyVarNode)->assign(
                Node::variable('origVal'),
            )->asExpression(),
            Node::variable('nameMap')->key($keyVarNode)->assign(
                Node::functionCall('strval', [Node::variable('origKey')]),
            )->asExpression(),
        ]);

        $forEachBody = [
            $keyVarNode->assign(
                Node::functionCall('strval', [Node::variable('origKey')]),
            )->asExpression(),
            Node::try(...$tryBody)->catches(
                Exception::class,
                // If exception is already a Message, use it directly; otherwise filter
                Node::if(
                    condition: Node::negate(Node::variable('exception')->instanceOf(Message::class)),
                    body: Node::variable('exception')->assign(
                        Node::property('exceptionFilter')->wrap()->call([Node::variable('exception')]),
                    )->asExpression(),
                ),
                Node::variable('context')->callMethod('sub', [
                    Node::functionCall('strval', [Node::variable('origKey')]),
                ])->callMethod('addMessage', [
                    Node::variable('exception'),
                    Node::value('?'),
                    Node::functionCall('strval', [Node::variable('origKey')]),
                ])->asExpression(),
            ),
        ];

        $keyConverterBody = [
            Node::variable('convertedSource')->assign(Node::value([]))->asExpression(),
            Node::variable('nameMap')->assign(Node::value([]))->asExpression(),
            Node::forEach(
                Node::variable('source'),
                'origKey',
                'origVal',
                $forEachBody,
            ),
            Node::variable('source')->assign(Node::variable('convertedSource'))->asExpression(),
            Node::variable('context')->callMethod('setNameMap', [
                Node::variable('nameMap'),
            ])->asExpression(),
        ];

        // Add early return if errors occurred
        $nodes = [];

        if ($wrapInArrayCheck) {
            // Wrap in is_array check (ObjectTypeMapper use case)
            $nodes[] = Node::if(
                condition: Node::functionCall('is_array', [Node::variable('source')]),
                body: $keyConverterBody,
            );
        } else {
            // No wrapper (ShapedArrayTypeMapper use case)
            $nodes = $keyConverterBody;
        }

        // Early return if key conversion caused errors
        $nodes[] = Node::if(
            condition: Node::variable('context')->callMethod('containsErrors'),
            body: Node::return(Node::value(null)),
        );

        return $nodes;
    }
}
