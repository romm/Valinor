<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Library\NewAttributeNode;
use CuyZ\Valinor\Compiler\Library\TypeAcceptNode;
use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\AttributeDefinition;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidNodeValue;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Utility\ValueDumper;
use Throwable;

use function implode;

/** @internal */
final class ConverterTypeMapperWrapper implements TypeMapper
{
    use TypeMapperMethodName;
    /**
     * @param array<int, array{converterIndex?: int, attrDef?: AttributeDefinition, paramType: Type, paramCount: int}> $matchingConverters
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
            $paramType = $converterInfo['paramType'];
            $paramCount = $converterInfo['paramCount'];

            // Condition: $from <= $index && TypeAccept($source, $paramType)
            $condition = Node::logicalAnd(
                Node::variable('from')->isLessOrEqualsTo(Node::value($index)),
                new TypeAcceptNode(Node::variable('source'), $paramType),
            );

            if ($paramCount === 1) {
                $nodes[] = $this->compileSingleParamConverter($converterInfo, $condition);
            } else {
                $nodes[] = $this->compileTwoParamConverter($converterInfo, $condition, $methodName, $index);
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
     * Compile a single-parameter converter call.
     *
     * Single-param converters are terminal: they directly return the result
     * after post-validation against the target type.
     *
     * @param array{converterIndex?: int, attrDef?: AttributeDefinition, paramType: Type, paramCount: int} $converterInfo
     */
    private function compileSingleParamConverter(array $converterInfo, Node $condition): Node
    {
        // Single-param converter: terminal, returns result directly
        $converterCall = $this->compileConverterCall($converterInfo, [Node::variable('source')]);

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

        return Node::if(
            condition: $condition,
            body: Node::try(...$tryBody)
                ->catches(Throwable::class, ...$catchBody)
                ->asExpression(),
        );
    }

    /**
     * Compile a two-parameter converter call.
     *
     * Two-param converters can chain: they receive a $next closure that can
     * optionally transform the value before passing it to the next converter
     * or the delegate mapper.
     *
     * @param array{converterIndex?: int, attrDef?: AttributeDefinition, paramType: Type, paramCount: int} $converterInfo
     */
    private function compileTwoParamConverter(
        array $converterInfo,
        Node $condition,
        string $methodName,
        int $index,
    ): Node {
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

        $converterCall = $this->compileConverterCall($converterInfo, [
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

        return Node::if(
            condition: $condition,
            body: Node::try(...$tryBody)
                ->catches(Throwable::class, ...$catchBody)
                ->asExpression(),
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
     * Compile the converter call node. Global converters use
     * `$this->converters[$index]`, attribute converters instantiate
     * the attribute inline and call `->map(...)`.
     *
     * @param array{converterIndex?: int, attrDef?: AttributeDefinition} $converterInfo
     * @param list<Node> $arguments
     */
    private function compileConverterCall(array $converterInfo, array $arguments): Node
    {
        if (isset($converterInfo['converterIndex'])) {
            return Node::this()->access('converters')->key(Node::value($converterInfo['converterIndex']))
                ->call($arguments);
        }

        return (new NewAttributeNode($converterInfo['attrDef']))->wrap()
            ->callMethod('map', $arguments);
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        // Include converter identifiers in hash so methods with different converter
        // sets (e.g. attribute converters on different properties of the same
        // type) get distinct method names.
        $hashInput = $this->targetType->toString();

        foreach ($this->matchingConverters as $conv) {
            if (isset($conv['converterIndex'])) {
                $hashInput .= '|g' . $conv['converterIndex'];
            } elseif (isset($conv['attrDef'])) {
                $attrDef = $conv['attrDef'];
                $hashInput .= '|a' . $attrDef->class->name . '|' . implode('|', $attrDef->reflectionParts) . '|' . $attrDef->attributeIndex;
            }
        }

        return self::buildMethodName('convert_and_map', $this->targetType->toString(), $hashInput);
    }
}
