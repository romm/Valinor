<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Library\NewAttributeNode;
use CuyZ\Valinor\Compiler\Library\TypeAcceptNode;
use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
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

use function CuyZ\Valinor\Compiler\{call, className, closure, if_, logicalAnd, negate, param, return_, ternary, this, try_, value, variable};
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

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            $target->assign(
                this()->callMethod(
                    method: $this->methodName(),
                    arguments: [
                        $value,
                        $context,
                        value(0),
                    ],
                ),
            )->asStatement(),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        // Register placeholder to prevent infinite recursion
        $class = $class->withMethod($methodName);

        // Delegate first so the type-specific method is available
        $class = $this->delegate->manipulateMapperClass($class, $settings, $typeMapperFactory);

        $nodes = [];

        foreach ($this->matchingConverters as $index => $converterInfo) {
            $paramType = $converterInfo['paramType'];
            $paramCount = $converterInfo['paramCount'];

            // Condition: $from <= $index && TypeAccept($source, $paramType)
            $condition = logicalAnd(
                variable('from')->isLessOrEqualsTo(value($index)),
                new TypeAcceptNode(variable('source'), $paramType),
            );

            if ($paramCount === 1) {
                $nodes[] = $this->compileSingleParamConverter($converterInfo, $condition);
            } else {
                $nodes[] = $this->compileTwoParamConverter($converterInfo, $condition, $methodName, $index);
            }
        }

        // Fall through to delegate type mapper
        $nodes[] = variable('delegateResult')->assign(value(null))->asStatement();

        $nodes = [
            ...$nodes,
            ...$this->delegate->buildMappingNodes(
                variable('source'),
                variable('context'),
                variable('delegateResult'),
            ),
        ];

        $nodes[] = return_(variable('delegateResult'));

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
                param('from', 'int'),
            ],
            returnType: 'mixed',
            body: $nodes,
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
        $converterCall = $this->compileConverterCall($converterInfo, [variable('source')]);

        $tryBody = [
            variable('converterResult')->assign($converterCall)->asStatement(),
            // Post-validate: check result matches target type
            if_(
                condition: negate($this->targetType->compiledAccept(variable('converterResult'))->wrap()),
                body: [
                    variable('context')->callMethod('addMessage', [
                        new MessageNode(InvalidNodeValue::from($this->targetType)),
                        value($this->targetType->toString()),
                        className(ValueDumper::class)->callStaticMethod('dump', [variable('converterResult')]),
                    ])->asStatement(),
                    return_(value(null)),
                ],
            ),
            return_(variable('converterResult')),
        ];

        $catchBody = $this->catchBody();

        return if_(
            condition: $condition,
            body: try_(...$tryBody)
                ->catches(Throwable::class, ...$catchBody)
                ->asStatement(),
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
        $nextClosure = closure(
            body: [
                variable('newValue')->assign(
                    ternary(
                        call('func_num_args', [])->isGreaterThan(value(0)),
                        call('func_get_arg', [value(0)]),
                        variable('source'),
                    ),
                )->asStatement(),
                return_(
                    this()->callMethod($methodName, [
                        variable('newValue'),
                        variable('context'),
                        value($index + 1),
                    ]),
                ),
            ],
            uses: ['source', 'context'],
        );

        $converterCall = $this->compileConverterCall($converterInfo, [
            variable('source'),
            $nextClosure,
        ]);

        $tryBody = [
            variable('converterResult')->assign($converterCall)->asStatement(),
            // Post-validate
            if_(
                condition: negate($this->targetType->compiledAccept(variable('converterResult'))->wrap()),
                body: [
                    variable('context')->callMethod('addMessage', [
                        new MessageNode(InvalidNodeValue::from($this->targetType)),
                        value($this->targetType->toString()),
                        className(ValueDumper::class)->callStaticMethod('dump', [variable('converterResult')]),
                    ])->asStatement(),
                    return_(value(null)),
                ],
            ),
            return_(variable('converterResult')),
        ];

        $catchBody = $this->catchBody();

        return if_(
            condition: $condition,
            body: try_(...$tryBody)
                ->catches(Throwable::class, ...$catchBody)
                ->asStatement(),
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
            if_(
                condition: variable('context')->callMethod('containsErrors'),
                body: return_(value(null)),
            ),
            if_(
                condition: negate(variable('exception')->instanceOf(Message::class)),
                body: variable('exception')->assign(
                    this()->access('exceptionFilter')->wrap()->call([variable('exception')]),
                )->asStatement(),
            ),
            variable('context')->callMethod('addMessage', [
                variable('exception'),
                value($this->targetType->toString()),
                className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
            ])->asStatement(),
            return_(value(null)),
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
            return this()->access('converters')->key(value($converterInfo['converterIndex']))
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
