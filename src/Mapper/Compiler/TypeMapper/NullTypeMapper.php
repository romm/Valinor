<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;

use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceIsNotNull;
use CuyZ\Valinor\Utility\ValueDumper;

use function CuyZ\Valinor\Compiler\{className, if_, param, return_, this, value, variable};

/** @internal */
final class NullTypeMapper implements TypeMapper
{
    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            $target->assign(
                this()->callMethod(
                    method: 'map_null',
                    arguments: [
                        $value,
                        $context,
                    ],
                ),
            )->asStatement(),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        if ($class->hasMethod('map_null')) {
            return $class;
        }

        return $class->withMethod(
            name: 'map_null',
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: 'null',
            body: [
                if_(
                    condition: variable('source')->different(value(null)),
                    body: [
                        variable('context')->callMethod(
                            method: 'addMessage',
                            arguments: [
                                new MessageNode(new SourceIsNotNull()),
                                value('null'),
                                className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
                            ]
                        )->asStatement(),
                        return_(value(null)),
                    ],
                ),
                return_(value(null)),
            ],
        );
    }
}
