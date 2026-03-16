<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\KeysCollision;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use Exception;

use function array_merge;
use function CuyZ\Valinor\Compiler\{call, forEach_, if_, negate, newClass, return_, this, try_, value, variable};

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
        $keyConverterIndices = $factory->keyConverterIndices();

        // Build converter chain: apply each converter to the key
        $keyVarNode = variable('ck');
        $converterNodes = [];

        foreach ($keyConverterIndices as $kcIndex) {
            $converterNodes[] = $keyVarNode->assign(
                this()->access('keyConverters')->key(value($kcIndex))->call([$keyVarNode]),
            )->asStatement();
        }

        $tryBody = array_merge($converterNodes, [
            if_(
                condition: call('array_key_exists', [$keyVarNode, variable('nameMap')]),
                body: new AddMessageNode(
                    variable('context')->callMethod('sub', [call('strval', [variable('origKey')])]),
                    newClass(KeysCollision::class, variable('nameMap')->key($keyVarNode), $keyVarNode),
                    '?',
                    call('strval', [variable('origKey')]),
                ),
            )->else([
                variable('convertedSource')->key($keyVarNode)->assign(
                    variable('origVal'),
                )->asStatement(),
                variable('nameMap')->key($keyVarNode)->assign(
                    call('strval', [variable('origKey')]),
                )->asStatement(),
            ]),
        ]);

        $forEachBody = [
            $keyVarNode->assign(
                call('strval', [variable('origKey')]),
            )->asStatement(),
            try_(...$tryBody)->catches(
                Exception::class,
                // If exception is already a Message, use it directly; otherwise filter
                if_(
                    condition: negate(variable('exception')->instanceOf(Message::class)),
                    body: variable('exception')->assign(
                        this()->access('exceptionFilter')->wrap()->call([variable('exception')]),
                    )->asStatement(),
                ),
                new AddMessageNode(
                    variable('context')->callMethod('sub', [call('strval', [variable('origKey')])]),
                    variable('exception'),
                    '?',
                    call('strval', [variable('origKey')]),
                ),
            ),
        ];

        $keyConverterBody = [
            variable('convertedSource')->assign(value([]))->asStatement(),
            variable('nameMap')->assign(value([]))->asStatement(),
            forEach_(
                variable('source'),
                'origKey',
                'origVal',
                $forEachBody,
            ),
            variable('source')->assign(variable('convertedSource'))->asStatement(),
            variable('context')->callMethod('setNameMap', [
                variable('nameMap'),
            ])->asStatement(),
        ];

        // Add early return if errors occurred
        $nodes = [];

        if ($wrapInArrayCheck) {
            // Wrap in is_array check (ObjectTypeMapper use case)
            $nodes[] = if_(
                condition: call('is_array', [variable('source')]),
                body: $keyConverterBody,
            );
        } else {
            // No wrapper (ShapedArrayTypeMapper use case)
            $nodes = $keyConverterBody;
        }

        // Early return if key conversion caused errors
        $nodes[] = if_(
            condition: variable('context')->callMethod('containsErrors'),
            body: return_(value(null)),
        );

        return $nodes;
    }
}
