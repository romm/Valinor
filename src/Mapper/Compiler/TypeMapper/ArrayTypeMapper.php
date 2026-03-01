<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TodoContext;
use CuyZ\Valinor\Mapper\Compiler\TodoMapper;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidIterableKeyType;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceIsEmptyArray;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Type\Types\ArrayType;
use CuyZ\Valinor\Type\Types\IterableType;
use CuyZ\Valinor\Type\Types\NonEmptyArrayType;

/** @internal */
final class ArrayTypeMapper implements TypeMapper
{
    public function __construct(
        private ArrayType|NonEmptyArrayType|IterableType $type,
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

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TodoMapper $todoMapper): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        $nodes = [];

        if ($settings->allowUndefinedValues) {
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: Node::variable('source')->assign(Node::value([]))->asExpression(),
            );
        }

        $nodes[] = Node::if(
            condition: Node::negate(Node::functionCall('is_iterable', [Node::variable('source')])),
            body: [
                Node::variable('context')->callMethod('addMessage', [
                    new MessageNode(new SourceMustBeIterable('todo')),
                    Node::value($this->type->toString()),
                    Node::variable('source'),
                ])->asExpression(),
                Node::return(Node::value(null)),
            ],
        );

        if ($this->type instanceof NonEmptyArrayType) {
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value([])),
                body: [
                    Node::variable('context')->callMethod('addMessage', [
                        new MessageNode(new SourceIsEmptyArray()),
                        Node::value($this->type->toString()),
                        Node::variable('source'),
                    ])->asExpression(),
                    Node::return(Node::value(null)),
                ],
            );
        }

        $nodes[] = Node::forEach(
            value: Node::variable('source'),
            key: 'key',
            item: 'value',
            body: [
                Node::if(
                    condition: Node::negate(Node::functionCall('is_string', [Node::variable('key')]))
                        ->and(Node::negate(Node::functionCall('is_int', [Node::variable('key')]))),
                    body: Node::throw(Node::newClass(InvalidIterableKeyType::class, Node::variable('key'), )->asExpression()),
                ),
            ],
        );

        return $class->withMethods(
            Node::method($methodName)
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', TodoContext::class),
                )
                ->withReturnType('?' . $this->type->nativeType()->toString())
                ->withBody(...$nodes),
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->type->toString()));

        return "map_array_{$slug}_" . hash('crc32', $this->type->toString());
    }
}
