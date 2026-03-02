<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\FunctionDefinition;
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

        // Register placeholder to prevent infinite recursion
        $class = $class->withMethods(Node::method($methodName));

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
                $inferArgNames[] = Node::value($arg->name());
            }
            $nodes[] = Node::variable('context')->callMethod('allowSuperfluousKeys', $inferArgNames)->asExpression();
        }

        // Build match expression to dispatch to the correct implementation
        $matchNode = Node::match(Node::variable('className'));
        foreach ($implMappers as $className => $implMapper) {
            $matchNode = $matchNode->withCase(
                Node::value($className),
                $implMapper->formatValueNode(
                    Node::variable('source'),
                    Node::variable('context'),
                ),
            );
        }
        // Default case: throw ObjectImplementationNotRegistered with the implementation list
        $matchNode = $matchNode->withDefaultCase(
            Node::throw(
                Node::newClass(
                    ObjectImplementationNotRegistered::class,
                    Node::variable('className'),
                    Node::value($this->type->className()),
                    Node::value(array_keys($this->implementations)),
                ),
            ),
        );

        $nodes[] = Node::return($matchNode);

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
        $inferCallNode = Node::this()->access('inferredMapping')->key(Node::value($interfaceClassName));

        if ($argCount === 0) {
            // No arguments: call infer function directly with no args
            $nodes[] = Node::try(
                Node::variable('className')->assign(
                    $inferCallNode->call(),
                )->asExpression(),
            )->catches(
                \Exception::class,
                Node::variable('context')->callMethod('addMessage', [
                    Node::property('exceptionFilter')->wrap()->call([Node::variable('exception')]),
                    Node::value($this->type->toString()),
                    Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                ])->asExpression(),
                Node::return(Node::value(null)),
            );
        } elseif ($argCount === 1) {
            // Single-arg infer: handle scalar flattening like ObjectTypeMapper.
            // Source can be either a scalar (passed directly) or an array with the arg key.
            $arg = $this->inferArguments->at(0);
            $argName = $arg->name();
            $argMapper = $typeMapperFactory->for($arg->type());
            $class = $argMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

            // Convert iterable to array if needed
            $nodes[] = Node::if(
                condition: Node::functionCall('is_iterable', [Node::variable('source')])
                    ->and(Node::negate(Node::functionCall('is_array', [Node::variable('source')]))),
                body: Node::variable('source')->assign(
                    Node::functionCall('iterator_to_array', [Node::variable('source')]),
                )->asExpression(),
            );

            // Map infer arg in isolation
            $nodes[] = Node::variable('inferContext')->assign(
                Node::variable('context')->callMethod('isolate'),
            )->asExpression();

            // Condition: source is an array with the arg key
            $keyedCondition = Node::functionCall('is_array', [Node::variable('source')])
                ->and(Node::functionCall('array_key_exists', [
                    Node::value($argName),
                    Node::variable('source'),
                ]));

            // If source is an array with the arg key, extract it
            $nodes[] = Node::if(
                condition: $keyedCondition,
                body: Node::variable('inferArg')->assign(
                    $argMapper->formatValueNode(
                        Node::variable('source')->key(Node::value($argName)),
                        Node::variable('inferContext')->callMethod('sub', [Node::value($argName)]),
                    ),
                )->asExpression(),
            );

            // Otherwise, use source directly (scalar flattening)
            $nodes[] = Node::if(
                condition: Node::negate($keyedCondition->wrap()),
                body: Node::variable('inferArg')->assign(
                    $argMapper->formatValueNode(
                        Node::variable('source'),
                        Node::variable('inferContext'),
                    ),
                )->asExpression(),
            );

            // If argument mapping failed, propagate errors
            $nodes[] = Node::if(
                condition: Node::variable('inferContext')->callMethod('containsErrors'),
                body: [
                    Node::variable('context')->callMethod('mergeFrom', [
                        Node::variable('inferContext'),
                    ])->asExpression(),
                    Node::return(Node::value(null)),
                ],
            );

            // Call the infer function with the single mapped argument
            $nodes[] = Node::try(
                Node::variable('className')->assign(
                    $inferCallNode->call([Node::variable('inferArg')]),
                )->asExpression(),
            )->catches(
                \Exception::class,
                Node::variable('context')->callMethod('addMessage', [
                    Node::property('exceptionFilter')->wrap()->call([Node::variable('exception')]),
                    Node::value($this->type->toString()),
                    Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                ])->asExpression(),
                Node::return(Node::value(null)),
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
            $nodes[] = Node::if(
                condition: Node::functionCall('is_iterable', [Node::variable('source')])
                    ->and(Node::negate(Node::functionCall('is_array', [Node::variable('source')]))),
                body: Node::variable('source')->assign(
                    Node::functionCall('iterator_to_array', [Node::variable('source')]),
                )->asExpression(),
            );

            // Check source is iterable/array
            $dumpedType = $typeMapperFactory->dumpType($this->type);
            $nodes[] = Node::if(
                condition: Node::negate(Node::functionCall('is_array', [Node::variable('source')])),
                body: [
                    Node::variable('context')->callMethod('addMessage', [
                        new MessageNode(new SourceMustBeIterable('value')),
                        Node::value($this->type->toString()),
                        Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                        Node::value($dumpedType),
                    ])->asExpression(),
                    Node::return(Node::value(null)),
                ],
            );

            // Map infer arguments in isolation
            $nodes[] = Node::variable('inferContext')->assign(
                Node::variable('context')->callMethod('isolate'),
            )->asExpression();

            // Extract and map each infer argument individually
            foreach ($this->inferArguments as $arg) {
                $argName = $arg->name();
                $argMapper = $argMappers[$argName];

                $nodes[] = Node::if(
                    condition: Node::functionCall('array_key_exists', [
                        Node::value($argName),
                        Node::variable('source'),
                    ]),
                    body: Node::variable('inferArg_' . $argName)->assign(
                        $argMapper->formatValueNode(
                            Node::variable('source')->key(Node::value($argName)),
                            Node::variable('inferContext')->callMethod('sub', [Node::value($argName)]),
                        ),
                    )->asExpression(),
                );
            }

            // If argument mapping failed, propagate errors
            $nodes[] = Node::if(
                condition: Node::variable('inferContext')->callMethod('containsErrors'),
                body: [
                    Node::variable('context')->callMethod('mergeFrom', [
                        Node::variable('inferContext'),
                    ])->asExpression(),
                    Node::return(Node::value(null)),
                ],
            );

            // Call the infer function with mapped arguments
            $callArgs = [];
            foreach ($this->inferArguments as $arg) {
                $callArgs[] = Node::variable('inferArg_' . $arg->name());
            }

            $nodes[] = Node::try(
                Node::variable('className')->assign(
                    $inferCallNode->call($callArgs),
                )->asExpression(),
            )->catches(
                \Exception::class,
                Node::variable('context')->callMethod('addMessage', [
                    Node::property('exceptionFilter')->wrap()->call([Node::variable('exception')]),
                    Node::value($this->type->toString()),
                    Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                ])->asExpression(),
                Node::return(Node::value(null)),
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
