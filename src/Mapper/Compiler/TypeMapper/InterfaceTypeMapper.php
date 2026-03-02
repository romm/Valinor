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
use CuyZ\Valinor\Type\ClassType;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Utility\ValueDumper;

use function array_keys;
use function implode;

use function count;
use function hash;
use function preg_replace;
use function strtolower;

/** @internal */
final class InterfaceTypeMapper implements TypeMapper
{
    /**
     * @param non-empty-array<string, ClassType> $implementations
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

        // Register the infer callback
        $callbackKey = self::inferCallbackKey($this->type->className());
        $typeMapperFactory->registerConstructorCallback(
            $callbackKey,
            $typeMapperFactory->inferCallbackFor($this->type->className()),
        );

        $nodes = [];

        // Pre-compile all implementation mappers without registered converters
        // (mirrors runtime shouldNotApplyConverters after interface inferring)
        $implMappers = [];
        foreach ($this->implementations as $className => $implType) {
            $implMapper = $typeMapperFactory->for($implType, applyConverters: false);
            $class = $implMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
            $implMappers[$className] = $implMapper;
        }

        $argCount = count($this->inferArguments);

        if ($argCount === 0) {
            // No arguments: call infer function directly with no args
            $nodes[] = Node::variable('className')->assign(
                Node::this()->access('constructorCallbacks')->key(Node::value($callbackKey))->call(),
            )->asExpression();
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
                condition: Node::negate($keyedCondition),
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
            $callNode = Node::this()->access('constructorCallbacks')->key(Node::value($callbackKey));
            $nodes[] = Node::variable('className')->assign(
                $callNode->call([Node::variable('inferArg')]),
            )->asExpression();
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
            $nodes[] = Node::if(
                condition: Node::negate(Node::functionCall('is_array', [Node::variable('source')])),
                body: [
                    Node::variable('context')->callMethod('addMessage', [
                        new MessageNode(new SourceMustBeIterable('value')),
                        Node::value($this->type->toString()),
                        Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
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
            $callNode = Node::this()->access('constructorCallbacks')->key(Node::value($callbackKey));

            $callArgs = [];
            foreach ($this->inferArguments as $arg) {
                $callArgs[] = Node::variable('inferArg_' . $arg->name());
            }

            $nodes[] = Node::variable('className')->assign(
                $callNode->call($callArgs),
            )->asExpression();
        }

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
        // Default case: throw ObjectImplementationNotRegistered with the registered implementations
        $implsKey = self::implementationsKey($this->type->className());
        $typeMapperFactory->registerConstructorCallback($implsKey, $this->implementations);

        $matchNode = $matchNode->withDefaultCase(
            Node::throw(
                Node::newClass(
                    ObjectImplementationNotRegistered::class,
                    Node::variable('className'),
                    Node::value($this->type->className()),
                    Node::this()->access('constructorCallbacks')->key(Node::value($implsKey)),
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
     * @param class-string $interfaceName
     * @return non-empty-string
     */
    public static function inferCallbackKey(string $interfaceName): string
    {
        return 'infer_' . hash('crc32', $interfaceName);
    }

    /**
     * @param class-string $interfaceName
     * @return non-empty-string
     */
    public static function implementationsKey(string $interfaceName): string
    {
        return 'impls_' . hash('crc32', $interfaceName);
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->type->toString()));

        return "map_interface_{$slug}_" . hash('crc32', $this->type->toString());
    }
}
