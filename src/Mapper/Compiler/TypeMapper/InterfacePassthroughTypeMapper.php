<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveObjectType;
use CuyZ\Valinor\Type\ObjectType;

use function hash;
use function preg_replace;
use function strtolower;

/** @internal */
final class InterfacePassthroughTypeMapper implements TypeMapper
{
    public function __construct(
        private ObjectType $type,
    ) {}

    public function formatValueNode(ComplianceNode $value, ComplianceNode $context): Node
    {
        return Node::this()->callMethod(
            method: $this->methodName(),
            arguments: [
                $value,
                $context,
            ],
        );
    }

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        return $class->withMethods(
            Node::method($methodName)
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', MappingContext::class),
                )
                ->withReturnType('mixed')
                ->withBody(
                    Node::if(
                        condition: Node::variable('source')->instanceOf($this->type->className()),
                        body: Node::return(Node::variable('source')),
                    ),
                    Node::throw(
                        Node::newClass(
                            CannotResolveObjectType::class,
                            Node::value($this->type->className()),
                        ),
                    )->asExpression(),
                ),
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->type->toString()));

        return "map_passthrough_{$slug}_" . hash('crc32', $this->type->toString());
    }
}
