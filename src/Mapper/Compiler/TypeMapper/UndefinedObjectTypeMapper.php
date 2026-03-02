<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotMapToPermissiveType;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidNodeValue;
use CuyZ\Valinor\Utility\ValueDumper;

/** @internal */
final class UndefinedObjectTypeMapper implements TypeMapper
{
    public function formatValueNode(ComplianceNode $value, ComplianceNode $context): Node
    {
        return Node::this()->callMethod(
            method: 'map_object',
            arguments: [$value, $context],
        );
    }

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        if (! $settings->allowPermissiveTypes) {
            throw CannotMapToPermissiveType::forType('object');
        }

        if ($class->hasMethod('map_object')) {
            return $class;
        }

        return $class->withMethods(
            Node::method('map_object')
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', MappingContext::class),
                )
                ->withReturnType('?object')
                ->withBody(
                    Node::if(
                        condition: Node::negate(Node::functionCall('is_object', [Node::variable('source')])),
                        body: [
                            Node::variable('context')->callMethod('addMessage', [
                                new MessageNode(new InvalidNodeValue()),
                                Node::value('object'),
                                Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                            ])->asExpression(),
                            Node::return(Node::value(null)),
                        ],
                    ),
                    Node::return(Node::variable('source')),
                ),
        );
    }
}
