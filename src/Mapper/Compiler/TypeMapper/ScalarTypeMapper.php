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
use CuyZ\Valinor\Type\FloatType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Types\UnionType;

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

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        $nodes = [];

        // Int-to-float auto-conversion (mirrors Shell::castFloatValue)
        if ($this->type instanceof FloatType) {
            $nodes[] = Node::if(
                condition: Node::functionCall('is_int', [Node::variable('source')]),
                body: Node::variable('source')->assign(
                    Node::variable('source')->castTo($this->type),
                )->asExpression(),
            );
        }

        if (! $settings->allowScalarValueCasting) {
            $nodes = [
                ...$nodes,
                Node::if(
                    condition: Node::negate($this->type->compiledAccept(Node::variable('source'))->wrap()),
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
            // Register canCast and cast callbacks for runtime use
            $canCastKey = 'canCast_' . hash('crc32', $this->type->toString());
            $castKey = 'cast_' . hash('crc32', $this->type->toString());
            $typeMapperFactory->registerConstructorCallback($canCastKey, $this->type->canCast(...));
            $typeMapperFactory->registerConstructorCallback($castKey, $this->type->cast(...));

            $nodes = [
                ...$nodes,
                Node::if(
                    condition: $this->type->compiledAccept(Node::variable('source')),
                    body: Node::return(Node::variable('source')),
                ),
                Node::if(
                    condition: Node::this()->access('constructorCallbacks')->key(Node::value($canCastKey))->call([Node::variable('source')]),
                    body: Node::return(
                        Node::this()->access('constructorCallbacks')->key(Node::value($castKey))->call([Node::variable('source')]),
                    ),
                ),
                Node::variable('context')->callMethod('addMessage', [
                    new MessageNode($this->type->errorMessage()),
                    Node::value($this->type->toString()),
                    Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                ])->asExpression(),
                Node::return(Node::value(null)),
            ];
        }

        return $class->withMethods(
            Node::method($methodName)
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', MappingContext::class),
                )
                ->withReturnType($this->nullableReturnType())
                ->withBody(...$nodes),
        );
    }

    private function nullableReturnType(): string
    {
        $nativeType = $this->type->nativeType();

        if ($nativeType instanceof UnionType) {
            return $nativeType->toString() . '|null';
        }

        return '?' . $nativeType->toString();
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->type->toString()));

        return "map_{$slug}_" . hash('crc32', $this->type->toString());
    }
}
