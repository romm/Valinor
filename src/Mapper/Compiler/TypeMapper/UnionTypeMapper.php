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
use CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveTypeFromUnion;
use CuyZ\Valinor\Type\Types\NullType;
use CuyZ\Valinor\Type\Types\UnionType;
use CuyZ\Valinor\Utility\ValueDumper;

use function hash;
use function preg_replace;
use function strtolower;

/** @internal */
final class UnionTypeMapper implements TypeMapper
{
    public function __construct(
        private UnionType $type,
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

        $nodes = [];

        $hasNull = false;
        $nonNullTypes = [];

        foreach ($this->type->types() as $subType) {
            if ($subType instanceof NullType) {
                $hasNull = true;
            } else {
                $nonNullTypes[] = $subType;
            }
        }

        // Null fast-path: if source is null and NullType is in the union, return null immediately
        if ($hasNull) {
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: Node::return(Node::value(null)),
            );
        }

        if (count($nonNullTypes) === 1) {
            // Only one non-null type: map directly without isolation
            $subMapper = $typeMapperFactory->for($nonNullTypes[0]);
            $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

            $nodes[] = Node::return(
                $subMapper->formatValueNode(
                    Node::variable('source'),
                    Node::variable('context'),
                ),
            );
        } else {
            // Multiple non-null types: try each in isolated context
            // For each type, try mapping; if no errors, return immediately
            // This is a simplified approach: first successful match wins
            foreach ($nonNullTypes as $i => $subType) {
                $subMapper = $typeMapperFactory->for($subType);
                $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

                $subCtxVar = "subContext_{$i}";
                $subResultVar = "subResult_{$i}";

                // Create isolated context
                $nodes[] = Node::variable($subCtxVar)->assign(
                    Node::variable('context')->callMethod('isolate'),
                )->asExpression();

                // Try mapping
                $nodes[] = Node::variable($subResultVar)->assign(
                    $subMapper->formatValueNode(
                        Node::variable('source'),
                        Node::variable($subCtxVar),
                    ),
                )->asExpression();

                // If no errors, return the result
                $nodes[] = Node::if(
                    condition: Node::negate(Node::variable($subCtxVar)->callMethod('containsErrors')),
                    body: Node::return(Node::variable($subResultVar)),
                );
            }

            // No type matched: add error
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: [
                    Node::variable('context')->callMethod('addMessage', [
                        new MessageNode(new CannotResolveTypeFromUnion(null)),
                        Node::value($this->type->toString()),
                        Node::value('*missing*'),
                    ])->asExpression(),
                    Node::return(Node::value(null)),
                ],
            );

            $nodes[] = Node::variable('context')->callMethod('addMessage', [
                new MessageNode(new CannotResolveTypeFromUnion('value')),
                Node::value($this->type->toString()),
                Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
            ])->asExpression();
            $nodes[] = Node::return(Node::value(null));
        }

        return $class->withMethods(
            Node::method($methodName)
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', MappingContext::class),
                )
                ->withReturnType('mixed')
                ->withBody(...$nodes),
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->type->toString()));

        return "map_union_{$slug}_" . hash('crc32', $this->type->toString());
    }
}
