<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\ClassDefinition;
use CuyZ\Valinor\Library\Settings;

use CuyZ\Valinor\Mapper\Compiler\TodoContext;
use CuyZ\Valinor\Mapper\Compiler\TodoMapper;
use CuyZ\Valinor\Mapper\Object\ObjectBuilder;

use function hash;
use function preg_replace;
use function strtolower;

final class ObjectTypeMapper implements TypeMapper
{
    public function __construct(
        private ClassDefinition $class,
        /** @var non-empty-list<ObjectBuilder> */
        private array $builders,
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

        $nodes = [
            Node::if(
                condition: $this->class->type->compiledAccept(Node::variable('source')),
                body: Node::return(Node::variable('source')),
            ),
        ];

        if ($settings->allowUndefinedValues) {
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: Node::variable('source')->assign(Node::value([]))->asExpression(),
            );
        }

        foreach ($this->builders as $builder) {
            $subNodes = [];

            foreach ($builder->describeArguments() as $argument) {
                $subMapper = $todoMapper->for($argument->type());

                $class = $subMapper->manipulateMapperClass($class, $settings, $todoMapper);

                $subNodes[$argument->name()] = $subMapper->formatValueNode(
                    Node::variable('source')->key(Node::value($argument->name())),
                    Node::variable('context')->callMethod('sub', [Node::value($argument->name())]),
                );
            }

            $nodes = [
                ...$nodes,
                Node::variable('values')->assign(
                    Node::array($subNodes),
                )->asExpression(),
                Node::if(
                    condition: Node::variable('context')->callMethod('messages')->different(Node::value([])),
                    body: Node::return(Node::value(null)),
                ),
                ...$builder->todo(Node::variable('values'))
            ];
        }

        return $class->withMethods(
            Node::method($methodName)
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', TodoContext::class),
                )
                ->withReturnType('?' . $this->class->type->nativeType()->toString())
                ->withBody(...$nodes),
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->class->type->toString()));

        return "map_object_{$slug}_" . hash('crc32c', $this->class->type->toString());
    }
}
