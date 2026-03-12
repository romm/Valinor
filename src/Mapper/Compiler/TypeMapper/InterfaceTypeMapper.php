<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\FunctionDefinition;

use function CuyZ\Valinor\Compiler\{call, className, if_, match_, negate, newClass, param, return_, this, throw_, try_, value, variable};
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Object\Arguments;
use CuyZ\Valinor\Mapper\Tree\Exception\ObjectImplementationNotRegistered;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Utility\ValueDumper;

use function array_keys;
use function count;

/** @internal */
final class InterfaceTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    /**
     * @param array<string, ClassType> $implementations
     */
    public function __construct(
        private ObjectType $type,
        private FunctionDefinition $inferFunction,
        private Arguments $inferArguments,
        private array $implementations,
    ) {}

    public function formatValueNode(Node $value, Node $context): Node
    {
        return this()->callMethod(
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

        // Register placeholder to prevent infinite recursion
        $class = $class->withMethod($methodName);

        $nodes = [];

        // Pre-compile all implementation mappers without registered converters
        // (mirrors runtime shouldNotApplyConverters after interface inferring)
        $implMappers = [];
        foreach ($this->implementations as $className => $implType) {
            $implMapper = $typeMapperFactory->for($implType, applyConverters: false);
            $class = $implMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
            $implMappers[$className] = $implMapper;
        }

        // Compile infer argument mapping and invocation
        $argCount = count($this->inferArguments);
        $className = $this->type->className();
        $this->compileInferArgMapping($argCount, $className, $class, $settings, $typeMapperFactory, $nodes);

        // Allow infer function argument names as superfluous keys in implementation mapping
        if ($argCount > 0) {
            $inferArgNames = [];
            foreach ($this->inferArguments as $arg) {
                $inferArgNames[] = value($arg->name());
            }
            $nodes[] = variable('context')->callMethod('allowSuperfluousKeys', $inferArgNames)->asStatement();
        }

        // Build match expression to dispatch to the correct implementation
        $matchNode = match_(variable('className'));
        foreach ($implMappers as $className => $implMapper) {
            $matchNode = $matchNode->withCase(
                value($className),
                $implMapper->formatValueNode(
                    variable('source'),
                    variable('context'),
                ),
            );
        }
        // Default case: throw ObjectImplementationNotRegistered with the implementation list
        $matchNode = $matchNode->withDefaultCase(
            throw_(
                newClass(
                    ObjectImplementationNotRegistered::class,
                    variable('className'),
                    value($this->type->className()),
                    value(array_keys($this->implementations)),
                ),
            ),
        );

        $nodes[] = return_($matchNode);

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: 'mixed',
            body: $nodes,
        );
    }

    /**
     * Compile infer argument mapping and invocation for 0, 1, or multi-arg cases.
     * @param class-string $interfaceClassName
     * @param non-empty-list<Node> $nodes Reference to nodes array to append to
     */
    private function compileInferArgMapping(
        int $argCount,
        string $interfaceClassName,
        AnonymousClassNode &$class,
        Settings $settings,
        TypeMapperFactory $typeMapperFactory,
        array &$nodes,
    ): void {
        $inferCallNode = this()->access('inferredMapping')->key(value($interfaceClassName));

        if ($argCount === 0) {
            // No arguments: call infer function directly with no args
            $nodes[] = try_(
                variable('className')->assign(
                    $inferCallNode->call(),
                )->asStatement(),
            )->catches(
                \Exception::class,
                variable('context')->callMethod('addMessage', [
                    this()->access('exceptionFilter')->wrap()->call([variable('exception')]),
                    value($this->type->toString()),
                    className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
                ])->asStatement(),
                return_(value(null)),
            );
        } elseif ($argCount === 1) {
            // Single-arg infer: handle scalar flattening like ObjectTypeMapper.
            // Source can be either a scalar (passed directly) or an array with the arg key.
            $arg = $this->inferArguments->at(0);
            $argName = $arg->name();
            $argMapper = $typeMapperFactory->for($arg->type());
            $class = $argMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

            // Convert iterable to array if needed
            $nodes[] = if_(
                condition: call('is_iterable', [variable('source')])
                    ->and(negate(call('is_array', [variable('source')]))),
                body: variable('source')->assign(
                    call('iterator_to_array', [variable('source')]),
                )->asStatement(),
            );

            // Map infer arg in isolation
            $nodes[] = variable('inferContext')->assign(
                variable('context')->callMethod('isolate'),
            )->asStatement();

            // Condition: source is an array with the arg key
            $keyedCondition = call('is_array', [variable('source')])
                ->and(call('array_key_exists', [
                    value($argName),
                    variable('source'),
                ]));

            // If source is an array with the arg key, extract it
            $nodes[] = if_(
                condition: $keyedCondition,
                body: variable('inferArg')->assign(
                    $argMapper->formatValueNode(
                        variable('source')->key(value($argName)),
                        variable('inferContext')->callMethod('sub', [value($argName)]),
                    ),
                )->asStatement(),
            );

            // Otherwise, use source directly (scalar flattening)
            $nodes[] = if_(
                condition: negate($keyedCondition->wrap()),
                body: variable('inferArg')->assign(
                    $argMapper->formatValueNode(
                        variable('source'),
                        variable('inferContext'),
                    ),
                )->asStatement(),
            );

            // If argument mapping failed, propagate errors
            $nodes[] = if_(
                condition: variable('inferContext')->callMethod('containsErrors'),
                body: [
                    variable('context')->callMethod('mergeFrom', [
                        variable('inferContext'),
                    ])->asStatement(),
                    return_(value(null)),
                ],
            );

            // Call the infer function with the single mapped argument
            $nodes[] = try_(
                variable('className')->assign(
                    $inferCallNode->call([variable('inferArg')]),
                )->asStatement(),
            )->catches(
                \Exception::class,
                variable('context')->callMethod('addMessage', [
                    this()->access('exceptionFilter')->wrap()->call([variable('exception')]),
                    value($this->type->toString()),
                    className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
                ])->asStatement(),
                return_(value(null)),
            );
        } else {
            // Multi-arg infer: extract each key from source, ignoring extra keys.

            // Pre-compile type mappers for each infer argument
            $argMappers = [];
            foreach ($this->inferArguments as $arg) {
                $argMapper = $typeMapperFactory->for($arg->type());
                $class = $argMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
                $argMappers[$arg->name()] = $argMapper;
            }

            // Convert iterable to array if needed
            $nodes[] = if_(
                condition: call('is_iterable', [variable('source')])
                    ->and(negate(call('is_array', [variable('source')]))),
                body: variable('source')->assign(
                    call('iterator_to_array', [variable('source')]),
                )->asStatement(),
            );

            // Check source is iterable/array
            $dumpedType = $typeMapperFactory->dumpType($this->type);
            $nodes[] = if_(
                condition: negate(call('is_array', [variable('source')])),
                body: [
                    variable('context')->callMethod('addMessage', [
                        new MessageNode(new SourceMustBeIterable('value')),
                        value($this->type->toString()),
                        className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
                        value($dumpedType),
                    ])->asStatement(),
                    return_(value(null)),
                ],
            );

            // Map infer arguments in isolation
            $nodes[] = variable('inferContext')->assign(
                variable('context')->callMethod('isolate'),
            )->asStatement();

            // Extract and map each infer argument individually
            foreach ($this->inferArguments as $arg) {
                $argName = $arg->name();
                $argMapper = $argMappers[$argName];

                $nodes[] = if_(
                    condition: call('array_key_exists', [
                        value($argName),
                        variable('source'),
                    ]),
                    body: variable('inferArg_' . $argName)->assign(
                        $argMapper->formatValueNode(
                            variable('source')->key(value($argName)),
                            variable('inferContext')->callMethod('sub', [value($argName)]),
                        ),
                    )->asStatement(),
                );
            }

            // If argument mapping failed, propagate errors
            $nodes[] = if_(
                condition: variable('inferContext')->callMethod('containsErrors'),
                body: [
                    variable('context')->callMethod('mergeFrom', [
                        variable('inferContext'),
                    ])->asStatement(),
                    return_(value(null)),
                ],
            );

            // Call the infer function with mapped arguments
            $callArgs = [];
            foreach ($this->inferArguments as $arg) {
                $callArgs[] = variable('inferArg_' . $arg->name());
            }

            $nodes[] = try_(
                variable('className')->assign(
                    $inferCallNode->call($callArgs),
                )->asStatement(),
            )->catches(
                \Exception::class,
                variable('context')->callMethod('addMessage', [
                    this()->access('exceptionFilter')->wrap()->call([variable('exception')]),
                    value($this->type->toString()),
                    className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
                ])->asStatement(),
                return_(value(null)),
            );
        }
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('map_interface', $this->type->toString());
    }
}
