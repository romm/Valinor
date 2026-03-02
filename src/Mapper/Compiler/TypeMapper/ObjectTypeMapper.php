<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\ClassDefinition;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Object\FunctionObjectBuilder;
use CuyZ\Valinor\Mapper\Object\ObjectBuilder;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Mapper\Tree\Message\UserlandError;
use Exception;
use CuyZ\Valinor\Type\CompositeTraversableType;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\ShapedArrayElement;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\StringValueType;
use CuyZ\Valinor\Type\Types\UnionType;
use CuyZ\Valinor\Utility\ValueDumper;

use function array_filter;
use function array_merge;
use function count;
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

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        // Register a placeholder method immediately to prevent infinite
        // recursion when types reference themselves (circular objects).
        $class = $class->withMethods(Node::method($methodName));

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

        // Apply key converters when source is an array
        if ($typeMapperFactory->hasKeyConverters()) {
            $keyConverterKeys = $typeMapperFactory->keyConverterKeys();

            $keyVarNode = Node::variable('ck');
            $converterNodes = [];

            foreach ($keyConverterKeys as $kcKey) {
                $converterNodes[] = $keyVarNode->assign(
                    Node::this()->access('constructorCallbacks')->key(Node::value($kcKey))->call([$keyVarNode]),
                )->asExpression();
            }

            $objTryBody = array_merge($converterNodes, [
                Node::variable('convertedSource')->key($keyVarNode)->assign(
                    Node::variable('origVal'),
                )->asExpression(),
                Node::variable('nameMap')->key($keyVarNode)->assign(
                    Node::functionCall('strval', [Node::variable('origKey')]),
                )->asExpression(),
            ]);

            $nodes[] = Node::if(
                condition: Node::functionCall('is_array', [Node::variable('source')]),
                body: [
                    Node::variable('convertedSource')->assign(Node::value([]))->asExpression(),
                    Node::variable('nameMap')->assign(Node::value([]))->asExpression(),
                    Node::forEach(
                        Node::variable('source'),
                        'origKey',
                        'origVal',
                        [
                            $keyVarNode->assign(
                                Node::functionCall('strval', [Node::variable('origKey')]),
                            )->asExpression(),
                            Node::try(...$objTryBody)->catches(
                                Exception::class,
                                Node::if(
                                    condition: Node::negate(Node::variable('exception')->instanceOf(Message::class)),
                                    body: Node::variable('exception')->assign(
                                        Node::property('exceptionFilter')->wrap()->call([Node::variable('exception')]),
                                    )->asExpression(),
                                ),
                                Node::variable('context')->callMethod('sub', [
                                    Node::functionCall('strval', [Node::variable('origKey')]),
                                ])->callMethod('addMessage', [
                                    Node::variable('exception'),
                                    Node::value('?'),
                                    Node::functionCall('strval', [Node::variable('origKey')]),
                                ])->asExpression(),
                            ),
                        ],
                    ),
                    Node::variable('source')->assign(Node::variable('convertedSource'))->asExpression(),
                    Node::variable('context')->callMethod('setNameMap', [
                        Node::variable('nameMap'),
                    ])->asExpression(),
                ],
            );
        }

        foreach ($this->builders as $builder) {
            // Register FunctionObjectBuilder callbacks for runtime injection
            if ($builder instanceof FunctionObjectBuilder) {
                $typeMapperFactory->registerConstructorCallback(
                    $builder->callbackKey(),
                    $builder->callback(),
                );
            }

            $arguments = $builder->describeArguments();
            $argCount = count($arguments);

            if ($argCount === 1) {
                // Single-argument flattening: source can be the value directly
                $argument = $arguments->at(0);
                $argName = $argument->name();
                $argType = $argument->type();

                // If the target type is a union type, filter out self-referencing subtypes
                // to prevent circular dependency
                $flattenedType = $argType;
                if ($argType instanceof UnionType) {
                    $subTypes = $argType->types();
                    $filtered = array_filter(
                        $subTypes,
                        fn (Type $subType) => ! $subType instanceof ObjectType || $subType->className() !== $this->class->type->className(),
                    );

                    if ($filtered !== $subTypes) {
                        $flattenedType = UnionType::from(...$filtered);
                    }
                }

                // Create shaped array mapper for keyed path (with flattened type)
                $shapedArrayType = new ShapedArrayType([
                    $argName => new ShapedArrayElement(
                        new StringValueType($argName),
                        $flattenedType,
                        ! $argument->isRequired(),
                        $argument->attributes(),
                    ),
                ]);
                $shapedMapper = new ShapedArrayTypeMapper($shapedArrayType, applyKeyConverters: false);
                $class = $shapedMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

                // Flat mapper for direct source mapping
                $flatMapper = $typeMapperFactory->for($flattenedType);

                // Wrap flat mapper with attribute converters from the argument
                $argAttrConverters = $typeMapperFactory->attributeConvertersFor($argument->attributes(), $flattenedType);

                if ($argAttrConverters !== []) {
                    $flatMapper = new ConverterTypeMapperWrapper($flattenedType, $flatMapper, $argAttrConverters);
                }

                $class = $flatMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

                // Default value + build nodes (reused by shaped and flat paths)
                $defaultAndBuildNodes = [];
                if (! $argument->isRequired()) {
                    $defaultAndBuildNodes[] = Node::if(
                        condition: Node::negate(Node::functionCall('array_key_exists', [
                            Node::value($argName),
                            Node::variable('values'),
                        ])),
                        body: Node::variable('values')->key(Node::value($argName))->assign(
                            Node::value($argument->defaultValue()),
                        )->asExpression(),
                    );
                }
                $defaultAndBuildNodes = [
                    Node::try(...$defaultAndBuildNodes, ...$builder->compile(Node::variable('values')))->catches(
                        UserlandError::class,
                        Node::variable('context')->callMethod('addMessage', [
                            Node::property('exceptionFilter')->wrap()->call([
                                Node::variable('exception')->callMethod('getPrevious'),
                            ]),
                            Node::value($this->class->type->toString()),
                            Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                        ])->asExpression(),
                        Node::return(Node::value(null)),
                    ),
                ];

                // Keyed path condition: source is array with the argument name as key
                $keyedCondition = Node::functionCall('is_array', [Node::variable('source')])
                    ->and(Node::functionCall('array_key_exists', [
                        Node::value($argName),
                        Node::variable('source'),
                    ]));

                if ($argType instanceof CompositeTraversableType) {
                    if ($settings->allowSuperfluousKeys) {
                        // When superfluous keys are allowed, always use keyed path if key exists
                    } else {
                        // Only use keyed path when source has exactly one key
                        $keyedCondition = $keyedCondition->and(
                            Node::functionCall('count', [Node::variable('source')])->equals(Node::value(1)),
                        );
                    }
                } else {
                    // For non-traversable types, also handle empty array via shaped mapper
                    $keyedCondition = $keyedCondition->or(
                        Node::variable('source')->equals(Node::value([])),
                    );
                }

                // Shaped array path: delegate to ShapedArrayTypeMapper
                $nodes[] = Node::if(
                    condition: $keyedCondition,
                    body: [
                        Node::variable('values')->assign(
                            $shapedMapper->formatValueNode(Node::variable('source'), Node::variable('context')),
                        )->asExpression(),
                        Node::if(
                            condition: Node::variable('values')->equals(Node::value(null)),
                            body: Node::return(Node::value(null)),
                        ),
                        ...$defaultAndBuildNodes,
                    ],
                );

                // Flat path: map source directly as the argument value
                $nodes[] = Node::variable('mappedValue')->assign(
                    $flatMapper->formatValueNode(
                        Node::variable('source'),
                        Node::variable('context'),
                    ),
                )->asExpression();

                $nodes[] = Node::if(
                    condition: Node::variable('context')->callMethod('containsErrors'),
                    body: Node::return(Node::value(null)),
                );

                $nodes[] = Node::variable('values')->assign(
                    Node::array([
                        $argName => Node::variable('mappedValue'),
                    ]),
                )->asExpression();

                $nodes = [...$nodes, ...$defaultAndBuildNodes];
            } else {
                // Multi-argument case: delegate to ShapedArrayTypeMapper
                $shapedArrayType = $arguments->toShapedArray();
                $shapedMapper = new ShapedArrayTypeMapper($shapedArrayType, applyKeyConverters: false);
                $class = $shapedMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

                $nodes[] = Node::variable('values')->assign(
                    $shapedMapper->formatValueNode(Node::variable('source'), Node::variable('context')),
                )->asExpression();

                $nodes[] = Node::if(
                    condition: Node::variable('values')->equals(Node::value(null)),
                    body: Node::return(Node::value(null)),
                );

                // Apply default values for optional arguments
                foreach ($arguments as $argument) {
                    if (! $argument->isRequired()) {
                        $nodes[] = Node::if(
                            condition: Node::negate(Node::functionCall('array_key_exists', [
                                Node::value($argument->name()),
                                Node::variable('values'),
                            ])),
                            body: Node::variable('values')->key(Node::value($argument->name()))->assign(
                                Node::value($argument->defaultValue()),
                            )->asExpression(),
                        );
                    }
                }

                $nodes[] = Node::try(...$builder->compile(Node::variable('values')))->catches(
                    UserlandError::class,
                    Node::variable('context')->callMethod('addMessage', [
                        Node::property('exceptionFilter')->wrap()->call([
                            Node::variable('exception')->callMethod('getPrevious'),
                        ]),
                        Node::value($this->class->type->toString()),
                        Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                    ])->asExpression(),
                    Node::return(Node::value(null)),
                );
            }
        }

        return $class->withMethods(
            Node::method($methodName)
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', MappingContext::class),
                )
                ->withReturnType('?' . $this->class->type->nativeType()->toString())
                ->withBody(...$nodes),
        );
    }

    /**
     * Returns the number of constructor arguments for the primary builder.
     * Used by UnionTypeMapper to compute struct specificity.
     */
    public function argumentCount(): int
    {
        return count($this->builders[0]->describeArguments());
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->class->type->toString()));

        return "map_object_{$slug}_" . hash('crc32', $this->class->type->toString());
    }
}
