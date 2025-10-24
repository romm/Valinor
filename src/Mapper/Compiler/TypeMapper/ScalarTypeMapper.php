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
use CuyZ\Valinor\Type\ScalarType;

use CuyZ\Valinor\Utility\ValueDumper;

use function hash;
use function preg_replace;
use function strtolower;

final class ScalarTypeMapper implements TypeMapper
{
    public function __construct(
        private ScalarType $type,
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

        if (! $settings->allowScalarValueCasting) {
            $nodes = [
                Node::if(
                    condition: Node::negate($this->type->compiledAccept(Node::variable('source'))),
                    body: [
                        Node::variable('context')->callMethod(
                            method: 'addMessage',
                            arguments: [
                                new MessageNode($this->type->errorMessage()),
                                Node::value($this->type->toString()),
                                Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                            ]
                        )->asExpression(),
                        Node::return(Node::value(null)),
                    ],
                ),
                Node::return(Node::variable('source')),
            ];
        } else {
            $nodes = [
                Node::if(
                    condition: Node::negate(Node::value(true)), // @todo canCast
                    body: Node::return(new MessageNode($this->type->errorMessage())), // @todo
                ),
                Node::return(Node::variable('source')->castTo($this->type)),
            ];
        }

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

        return "map_{$slug}_" . hash('crc32c', $this->type->toString());
    }
}
