<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Library\TypeAcceptNode;
use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidNodeValue;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Utility\ValueDumper;
use Throwable;

/** @internal */
final class ConverterTypeMapperWrapper implements TypeMapper
{
    use TypeMapperMethodName;
    /**
     * @param array<int, array{key: string, paramType: Type, paramCount: int}> $matchingConverters
     */
    public function __construct(
        private Type $targetType,
        private TypeMapper $delegate,
        private array $matchingConverters,
    ) {}

    public function formatValueNode(ComplianceNode $value, ComplianceNode $context): Node
    {
        return Node::this()->callMethod(
            method: $this->methodName(),
            arguments: [
                $value,
                $context,
                Node::value(0),
            ],
        );
    }

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        // Register placeholder to prevent infinite recursion
        $class = $class->withMethods(Node::method($methodName));

        // Delegate first so the type-specific method is available
        $class = $this->delegate->manipulateMapperClass($class, $settings, $typeMapperFactory);

        $nodes = [];

        foreach ($this->matchingConverters as $index => $converterInfo) {
            $callbackKey = $converterInfo['key'];
            $paramType = $converterInfo['paramType'];
            $paramCount = $converterInfo['paramCount'];

            // Condition: $from <= $index && TypeAccept($source, $paramType)
            $condition = Node::logicalAnd(
                Node::variable('from')->isLessOrEqualsTo(Node::value($index)),
                new TypeAcceptNode(Node::variable('source'), $paramType),
            );

            if ($paramCount === 1) {
                // Single-param converter: terminal, returns result directly
                $converterCall = Node::this()->access('constructorCallbacks')
                    ->key(Node::value($callbackKey))
                    ->call([Node::variable('source')]);

                $tryBody = [
                    Node::variable('converterResult')->assign($converterCall)->asExpression(),
                    // Post-validate: check result matches target type
                    Node::if(
                        condition: Node::negate($this->targetType->compiledAccept(Node::variable('converterResult'))->wrap()),
                        body: [
                            Node::variable('context')->callMethod('addMessage', [
                                new MessageNode(InvalidNodeValue::from($this->targetType)),
                                Node::value($this->targetType->toString()),
                                Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('converterResult')]),
                            ])->asExpression(),
                            Node::return(Node::value(null)),
                        ],
                    ),
                    Node::return(Node::variable('converterResult')),
                ];

                $catchBody = $this->catchBody();

                $nodes[] = Node::if(
                    condition: $condition,
                    body: Node::try(...$tryBody)
                        ->catches(Throwable::class, ...$catchBody)
                        ->asExpression(),
                );
            } else {
                // Two-param converter: chain with $next closure
                $nextClosure = Node::closure(
                    Node::variable('newValue')->assign(
                        Node::ternary(
                            Node::functionCall('func_num_args', [])->isGreaterThan(Node::value(0)),
                            Node::functionCall('func_get_arg', [Node::value(0)]),
                            Node::variable('source'),
                        ),
                    )->asExpression(),
                    Node::return(
                        Node::this()->callMethod($methodName, [
                            Node::variable('newValue'),
                            Node::variable('context'),
                            Node::value($index + 1),
                        ]),
                    ),
                )->uses('source', 'context');

                $converterCall = Node::this()->access('constructorCallbacks')
                    ->key(Node::value($callbackKey))
                    ->call([
                        Node::variable('source'),
                        $nextClosure,
                    ]);

                $tryBody = [
                    Node::variable('converterResult')->assign($converterCall)->asExpression(),
                    // Post-validate
                    Node::if(
                        condition: Node::negate($this->targetType->compiledAccept(Node::variable('converterResult'))->wrap()),
                        body: [
                            Node::variable('context')->callMethod('addMessage', [
                                new MessageNode(InvalidNodeValue::from($this->targetType)),
                                Node::value($this->targetType->toString()),
                                Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('converterResult')]),
                            ])->asExpression(),
                            Node::return(Node::value(null)),
                        ],
                    ),
                    Node::return(Node::variable('converterResult')),
                ];

                $catchBody = $this->catchBody();

                $nodes[] = Node::if(
                    condition: $condition,
                    body: Node::try(...$tryBody)
                        ->catches(Throwable::class, ...$catchBody)
                        ->asExpression(),
                );
            }
        }

        // Fall through to delegate type mapper
        $nodes[] = Node::return(
            $this->delegate->formatValueNode(
                Node::variable('source'),
                Node::variable('context'),
            ),
        );

        return $class->withMethods(
            Node::method($methodName)
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', MappingContext::class),
                    Node::parameterDeclaration('from', 'int'),
                )
                ->withReturnType('mixed')
                ->withBody(...$nodes),
        );
    }

    /**
     * Generate catch body for converter exception handling.
     * The TryNode uses $exception as the variable name.
     *
     * @return list<Node>
     */
    private function catchBody(): array
    {
        return [
            // If context already has errors (from inner mapping failure),
            // just return null - errors are already recorded
            Node::if(
                condition: Node::variable('context')->callMethod('containsErrors'),
                body: Node::return(Node::value(null)),
            ),
            Node::if(
                condition: Node::negate(Node::variable('exception')->instanceOf(Message::class)),
                body: Node::variable('exception')->assign(
                    Node::property('exceptionFilter')->wrap()->call([Node::variable('exception')]),
                )->asExpression(),
            ),
            Node::variable('context')->callMethod('addMessage', [
                Node::variable('exception'),
                Node::value($this->targetType->toString()),
                Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
            ])->asExpression(),
            Node::return(Node::value(null)),
        ];
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        // Include converter keys in hash so methods with different converter
        // sets (e.g. attribute converters on different properties of the same
        // type) get distinct method names.
        $hashInput = $this->targetType->toString();

        foreach ($this->matchingConverters as $conv) {
            $hashInput .= '|' . $conv['key'];
        }

        return self::buildMethodName('convert_and_map', $this->targetType->toString(), $hashInput);
    }
}
