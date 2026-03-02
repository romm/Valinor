<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceIsNotNull;
use CuyZ\Valinor\Utility\ValueDumper;

/** @internal */
final class NullTypeMapper implements TypeMapper
{
    public function formatValueNode(ComplianceNode $value, ComplianceNode $context): Node
    {
        return Node::this()->callMethod(
            method: 'map_null',
            arguments: [
                $value,
                $context,
            ],
        );
    }

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        if ($class->hasMethod('map_null')) {
            return $class;
        }

        return $class->withMethods(
            Node::method('map_null')
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', MappingContext::class),
                )
                ->withReturnType('null')
                ->withBody(
                    Node::if(
                        condition: Node::variable('source')->different(Node::value(null)),
                        body: [
                            Node::variable('context')->callMethod(
                                method: 'addMessage',
                                arguments: [
                                    new MessageNode(new SourceIsNotNull()),
                                    Node::value('null'),
                                    Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                                ]
                            )->asExpression(),
                            Node::return(Node::value(null)),
                        ],
                    ),
                    Node::return(Node::value(null)),
                ),
        );
    }
}
