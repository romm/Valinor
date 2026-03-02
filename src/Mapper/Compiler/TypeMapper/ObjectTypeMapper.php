<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\ClassDefinition;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Object\Exception\CannotFindObjectBuilder;
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

        // Compute effective type signature using TypeDumper for error messages
        $dumpedType = $typeMapperFactory->dumpType($this->class->type);

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

            // If key conversion caused errors, stop immediately
            $nodes[] = Node::if(
                condition: Node::variable('context')->callMethod('containsErrors'),
                body: Node::return(Node::value(null)),
            );
        }

        $hasMultipleBuilders = count($this->builders) > 1;

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

            if ($hasMultipleBuilders) {
                // Multi-builder: use isolated context per builder, skip on failure
                $class = $this->compileBuilderWithIsolation($class, $settings, $typeMapperFactory, $builder, $arguments, $argCount, $nodes, $dumpedType);
            } elseif ($argCount === 1) {
                $this->compileSingleArgBuilder($class, $settings, $typeMapperFactory, $builder, $arguments, $nodes, $dumpedType);
            } else {
                $this->compileMultiArgBuilder($class, $settings, $typeMapperFactory, $builder, $arguments, $nodes, $dumpedType);
            }
        }

        if ($hasMultipleBuilders) {
            // All builders failed: report error
            $nodes[] = Node::variable('context')->callMethod('addMessage', [
                new MessageNode(new CannotFindObjectBuilder()),
                Node::value($this->class->type->toString()),
                Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                Node::value($dumpedType),
            ])->asExpression();
            $nodes[] = Node::return(Node::value(null));
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
     * Compile a builder with context isolation for multi-builder fallback.
     * If argument mapping fails, skips to the next builder.
     *
     * @param non-empty-list<Node> $nodes
     */
    private function compileBuilderWithIsolation(
        AnonymousClassNode &$class,
        Settings $settings,
        TypeMapperFactory $typeMapperFactory,
        ObjectBuilder $builder,
        \CuyZ\Valinor\Mapper\Object\Arguments $arguments,
        int $argCount,
        array &$nodes,
        string $dumpedType,
    ): AnonymousClassNode {
        // Build shaped array mapper for argument validation
        $shapedArrayType = ($argCount === 1)
            ? new ShapedArrayType([
                $arguments->at(0)->name() => new ShapedArrayElement(
                    new StringValueType($arguments->at(0)->name()),
                    $arguments->at(0)->type(),
                    ! $arguments->at(0)->isRequired(),
                    $arguments->at(0)->attributes(),
                ),
            ])
            : $arguments->toShapedArray();

        $shapedMapper = new ShapedArrayTypeMapper($shapedArrayType, applyKeyConverters: false);
        $class = $shapedMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

        // For single-arg builders, also prepare the flat mapper
        $hasFlatPath = ($argCount === 1);
        $flatMapper = null;
        $argName = null;
        $argType = null;
        $flattenedType = null;

        if ($hasFlatPath) {
            $argument = $arguments->at(0);
            $argName = $argument->name();
            $argType = $argument->type();

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

            $flatMapper = $typeMapperFactory->for($flattenedType);

            $argAttrConverters = $typeMapperFactory->attributeConvertersFor($argument->attributes(), $flattenedType);
            if ($argAttrConverters !== []) {
                $flatMapper = new ConverterTypeMapperWrapper($flattenedType, $flatMapper, $argAttrConverters);
            }

            $class = $flatMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
        }

        // Build default values + construction as a single try-catch
        $defaultNodes = [];
        foreach ($arguments as $argument) {
            if (! $argument->isRequired()) {
                $defaultNodes[] = Node::if(
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

        $tryBody = array_merge($defaultNodes, $builder->compile(Node::variable('values')));
        $buildNodes = [
            Node::try(...$tryBody)->catches(
                UserlandError::class,
                Node::variable('context')->callMethod('addMessage', [
                    Node::property('exceptionFilter')->wrap()->call([
                        Node::variable('exception')->callMethod('getPrevious'),
                    ]),
                    Node::value($this->class->type->toString()),
                    Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                    Node::value($dumpedType),
                ])->asExpression(),
                Node::return(Node::value(null)),
            )->catches(
                Message::class,
                Node::variable('context')->callMethod('addMessage', [
                    Node::variable('exception'),
                    Node::value($this->class->type->toString()),
                    Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                    Node::value($dumpedType),
                ])->asExpression(),
                Node::return(Node::value(null)),
            ),
        ];

        if ($hasFlatPath) {
            // Single-arg with flat path: try keyed first, then flat
            $keyedCondition = Node::functionCall('is_array', [Node::variable('source')])
                ->and(Node::functionCall('array_key_exists', [
                    Node::value($argName),
                    Node::variable('source'),
                ]));

            if ($argType instanceof CompositeTraversableType) {
                if (! $settings->allowSuperfluousKeys) {
                    $keyedCondition = $keyedCondition->and(
                        Node::functionCall('count', [Node::variable('source')])->equals(Node::value(1)),
                    );
                }
            } else {
                $keyedCondition = $keyedCondition->or(
                    Node::variable('source')->equals(Node::value([])),
                );
            }

            // Keyed path with isolation
            $keyedBody = [
                Node::variable('isolatedCtx')->assign(
                    Node::variable('context')->callMethod('isolate'),
                )->asExpression(),
                Node::variable('values')->assign(
                    $shapedMapper->formatValueNode(Node::variable('source'), Node::variable('isolatedCtx')),
                )->asExpression(),
                Node::if(
                    condition: Node::negate(Node::variable('isolatedCtx')->callMethod('containsErrors')),
                    body: $buildNodes,
                ),
            ];

            $nodes[] = Node::if(
                condition: $keyedCondition,
                body: $keyedBody,
            );

            // Flat path with isolation
            $nodes[] = Node::variable('isolatedCtx')->assign(
                Node::variable('context')->callMethod('isolate'),
            )->asExpression();
            $nodes[] = Node::variable('mappedValue')->assign(
                $flatMapper->formatValueNode(
                    Node::variable('source'),
                    Node::variable('isolatedCtx'),
                ),
            )->asExpression();
            $nodes[] = Node::if(
                condition: Node::negate(Node::variable('isolatedCtx')->callMethod('containsErrors')),
                body: [
                    Node::variable('values')->assign(
                        Node::array([
                            $argName => Node::variable('mappedValue'),
                        ]),
                    )->asExpression(),
                    ...$buildNodes,
                ],
            );
        } else {
            // Multi-arg path with isolation
            $nodes[] = Node::variable('isolatedCtx')->assign(
                Node::variable('context')->callMethod('isolate'),
            )->asExpression();
            $nodes[] = Node::variable('values')->assign(
                $shapedMapper->formatValueNode(Node::variable('source'), Node::variable('isolatedCtx')),
            )->asExpression();
            $nodes[] = Node::if(
                condition: Node::negate(Node::variable('isolatedCtx')->callMethod('containsErrors')),
                body: $buildNodes,
            );
        }

        return $class;
    }

    /**
     * Compile single-argument builder (no isolation, direct return on error).
     *
     * @param non-empty-list<Node> $nodes
     */
    private function compileSingleArgBuilder(
        AnonymousClassNode &$class,
        Settings $settings,
        TypeMapperFactory $typeMapperFactory,
        ObjectBuilder $builder,
        \CuyZ\Valinor\Mapper\Object\Arguments $arguments,
        array &$nodes,
        string $dumpedType,
    ): void {
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
                    Node::value($dumpedType),
                ])->asExpression(),
                Node::return(Node::value(null)),
            )->catches(
                Message::class,
                Node::variable('context')->callMethod('addMessage', [
                    Node::variable('exception'),
                    Node::value($this->class->type->toString()),
                    Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                    Node::value($dumpedType),
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
    }

    /**
     * Compile multi-argument builder (no isolation, direct return on error).
     *
     * @param non-empty-list<Node> $nodes
     */
    private function compileMultiArgBuilder(
        AnonymousClassNode &$class,
        Settings $settings,
        TypeMapperFactory $typeMapperFactory,
        ObjectBuilder $builder,
        \CuyZ\Valinor\Mapper\Object\Arguments $arguments,
        array &$nodes,
        string $dumpedType,
    ): void {
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
                Node::value($dumpedType),
            ])->asExpression(),
            Node::return(Node::value(null)),
        )->catches(
            Message::class,
            Node::variable('context')->callMethod('addMessage', [
                Node::variable('exception'),
                Node::value($this->class->type->toString()),
                Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                Node::value($dumpedType),
            ])->asExpression(),
            Node::return(Node::value(null)),
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
