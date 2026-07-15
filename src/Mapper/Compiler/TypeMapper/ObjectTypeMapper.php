<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\ClassDefinition;

use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Http\HttpRequest;
use CuyZ\Valinor\Mapper\Object\Exception\CannotFindObjectBuilder;
use CuyZ\Valinor\Mapper\Object\ObjectBuilder;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Mapper\Tree\Message\UserlandError;
use CuyZ\Valinor\Type\CompositeTraversableType;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\ShapedArrayElement;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\StringValueType;
use CuyZ\Valinor\Type\Types\UnionType;

use function array_filter;

use function array_merge;

use function count;
use function CuyZ\Valinor\Compiler\{array_, call, dumpValue, if_, negate, param, return_, this, try_, value, variable};

/** @internal */
final class ObjectTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;

    public function __construct(
        private ClassDefinition $class,
        /** @var non-empty-list<ObjectBuilder> */
        private array $builders,
        private Settings $settings,
        private ?string $variant = null,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            $target->assign(
                this()->callMethod(
                    method: $this->methodName(),
                    arguments: [$value, $context],
                ),
            )->asStatement(),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        // Register a placeholder method immediately to prevent infinite
        // recursion when types reference themselves (circular objects).
        $class = $class->withMethod($methodName);

        // Compute effective type signature using TypeDumper for error messages
        $dumpedType = $typeMapperFactory->dumpType($this->class->type);

        $nodes = [
            if_(
                condition: $this->class->type->compiledAccept(variable('source')),
                then: return_(variable('source')),
            ),
        ];

        if ($this->settings->allowUndefinedValues) {
            $nodes[] = if_(
                condition: variable('source')->equals(value(null)),
                then: variable('source')->assign(value([]))->asStatement(),
            );
        }

        $hasMultipleBuilders = count($this->builders) > 1;

        foreach ($this->builders as $builder) {
            $arguments = $builder->describeArguments();
            $argCount = count($arguments);

            if ($hasMultipleBuilders) {
                // Multi-builder: use isolated context per builder, skip on failure
                $class = $this->compileBuilderWithIsolation($class, $typeMapperFactory, $builder, $arguments, $argCount, $nodes, $dumpedType);
            } elseif ($argCount === 1) {
                $this->compileSingleArgBuilder($class, $typeMapperFactory, $builder, $arguments, $nodes, $dumpedType);
            } else {
                $this->compileMultiArgBuilder($class, $typeMapperFactory, $builder, $arguments, $nodes, $dumpedType);
            }
        }

        if ($hasMultipleBuilders) {
            // All builders failed: report error
            $nodes[] = new AddMessageNode(variable('context'), new CannotFindObjectBuilder(), $this->class->type->toString(), dumpValue(variable('source')), $dumpedType);
            $nodes[] = return_(value(null));
        }

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: '?' . $this->class->type->nativeType()->toString(),
            body: $nodes,
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
        TypeMapperFactory $typeMapperFactory,
        ObjectBuilder $builder,
        \CuyZ\Valinor\Mapper\Object\Arguments $arguments,
        int $argCount,
        array &$nodes,
        string $dumpedType,
    ): AnonymousClassNode {
        // For single-arg builders, prepare both shaped and flat mappers
        $hasFlatPath = ($argCount === 1);
        $flatMapper = null;
        $argName = null;
        $argType = null;
        $flattenedType = null;
        $shapedMapper = null;

        if ($hasFlatPath) {
            $mappers = $this->prepareSingleArgMappers($arguments->at(0), $typeMapperFactory);
            $shapedMapper = $mappers['shapedMapper'];
            $flatMapper = $mappers['flatMapper'];
            $flattenedType = $mappers['flattenedType'];
            $argName = $arguments->at(0)->name();
            $argType = $arguments->at(0)->type();
            $class = $shapedMapper->manipulateMapperClass($class, $typeMapperFactory);
            $class = $flatMapper->manipulateMapperClass($class, $typeMapperFactory);
        } else {
            // Build shaped array mapper for multi-arg validation
            $shapedArrayType = $arguments->toShapedArray();
            $shapedMapper = $typeMapperFactory->forObjectArguments($shapedArrayType);
            $class = $shapedMapper->manipulateMapperClass($class, $typeMapperFactory);
        }

        // Build default values + construction as a single try-catch
        $defaultNodes = [];
        foreach ($arguments as $argument) {
            if (! $argument->isRequired()) {
                $defaultNodes[] = if_(
                    condition: negate(call('array_key_exists', [
                        value($argument->name()),
                        variable('values'),
                    ])),
                    then: variable('values')->key(value($argument->name()))->assign(
                        value($argument->defaultValue()),
                    )->asStatement(),
                );
            }
        }

        $tryBody = array_merge($defaultNodes, $builder->compile(variable('values')));
        $buildNodes = [
            $this->buildConstructionTryNode($tryBody, $dumpedType),
        ];

        if ($hasFlatPath) {
            // Single-arg with flat path: try keyed first, then flat
            $keyedCondition = call('is_array', [variable('source')])
                ->and(call('array_key_exists', [
                    value($argName),
                    variable('source'),
                ]));

            if ($argType instanceof CompositeTraversableType) {
                if (! $this->settings->allowSuperfluousKeys) {
                    $keyedCondition = $keyedCondition->and(
                        call('count', [variable('source')])->equals(value(1)),
                    );
                }
            } else {
                $keyedCondition = $keyedCondition->or(
                    variable('source')->equals(value([])),
                );
            }

            // The values of an HTTP request are spread over the arguments, so
            // it always goes through the shaped array, never the flat path.
            $keyedCondition = variable('source')->instanceOf(HttpRequest::class)->or($keyedCondition->wrap());

            // Keyed path with isolation
            $keyedBody = [
                variable('isolatedCtx')->assign(
                    variable('context')->callMethod('isolate'),
                )->asStatement(),
                ...$shapedMapper->buildMappingNodes(variable('source'), variable('isolatedCtx'), variable('values')),
                if_(
                    condition: negate(variable('isolatedCtx')->callMethod('containsErrors')),
                    then: [
                        variable('context')->callMethod('inheritChildrenCountFrom', [variable('isolatedCtx')])->asStatement(),
                        ...$buildNodes,
                    ],
                ),
            ];

            $nodes[] = if_(
                condition: $keyedCondition,
                then: $keyedBody,
            );

            // Flat path with isolation
            $nodes[] = variable('isolatedCtx')->assign(
                variable('context')->callMethod('isolate'),
            )->asStatement();

            $nodes = [
                ...$nodes,
                ...$flatMapper->buildMappingNodes(
                    variable('source'),
                    variable('isolatedCtx'),
                    variable('mappedValue'),
                ),
            ];

            $nodes[] = if_(
                condition: negate(variable('isolatedCtx')->callMethod('containsErrors')),
                then: [
                    variable('context')->callMethod('inheritChildrenCountFrom', [variable('isolatedCtx')])->asStatement(),
                    variable('values')->assign(
                        array_([
                            $argName => variable('mappedValue'),
                        ]),
                    )->asStatement(),
                    ...$buildNodes,
                ],
            );
        } else {
            // Multi-arg path with isolation
            $nodes[] = variable('isolatedCtx')->assign(
                variable('context')->callMethod('isolate'),
            )->asStatement();
            $nodes = [...$nodes, ...$shapedMapper->buildMappingNodes(variable('source'), variable('isolatedCtx'), variable('values'))];
            $nodes[] = if_(
                condition: negate(variable('isolatedCtx')->callMethod('containsErrors')),
                then: [
                    variable('context')->callMethod('inheritChildrenCountFrom', [variable('isolatedCtx')])->asStatement(),
                    ...$buildNodes,
                ],
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
        TypeMapperFactory $typeMapperFactory,
        ObjectBuilder $builder,
        \CuyZ\Valinor\Mapper\Object\Arguments $arguments,
        array &$nodes,
        string $dumpedType,
    ): void {
        $argument = $arguments->at(0);
        $argName = $argument->name();
        $argType = $argument->type();

        // Prepare both shaped and flat mappers for single-arg builder
        $mappers = $this->prepareSingleArgMappers($argument, $typeMapperFactory);
        $shapedMapper = $mappers['shapedMapper'];
        $flatMapper = $mappers['flatMapper'];
        $flattenedType = $mappers['flattenedType'];

        $class = $shapedMapper->manipulateMapperClass($class, $typeMapperFactory);
        $class = $flatMapper->manipulateMapperClass($class, $typeMapperFactory);

        // Default value + build nodes (reused by shaped and flat paths)
        $defaultAndBuildNodes = [];
        if (! $argument->isRequired()) {
            $defaultAndBuildNodes[] = if_(
                condition: negate(call('array_key_exists', [
                    value($argName),
                    variable('values'),
                ])),
                then: variable('values')->key(value($argName))->assign(
                    value($argument->defaultValue()),
                )->asStatement(),
            );
        }
        $tryBody = array_merge($defaultAndBuildNodes, $builder->compile(variable('values')));
        $defaultAndBuildNodes = [
            $this->buildConstructionTryNode($tryBody, $dumpedType),
        ];

        // Keyed path condition: source is array with the argument name as key
        $keyedCondition = call('is_array', [variable('source')])
            ->and(call('array_key_exists', [
                value($argName),
                variable('source'),
            ]));

        if ($argType instanceof CompositeTraversableType) {
            if ($this->settings->allowSuperfluousKeys) {
                // When superfluous keys are allowed, always use keyed path if key exists
            } else {
                // Only use keyed path when source has exactly one key
                $keyedCondition = $keyedCondition->and(
                    call('count', [variable('source')])->equals(value(1)),
                );
            }
        } else {
            // For non-traversable types, also handle empty array via shaped mapper
            $keyedCondition = $keyedCondition->or(
                variable('source')->equals(value([])),
            );
        }

        // The values of an HTTP request are spread over the arguments, so it
        // always goes through the shaped array, never the flat path.
        $keyedCondition = variable('source')->instanceOf(HttpRequest::class)->or($keyedCondition->wrap());

        // Shaped array path: delegate to ShapedArrayTypeMapper
        $nodes[] = if_(
            condition: $keyedCondition,
            then: [
                ...$shapedMapper->buildMappingNodes(variable('source'), variable('context'), variable('values')),
                if_(
                    condition: variable('values')->equals(value(null)),
                    then: return_(value(null)),
                ),
                ...$defaultAndBuildNodes,
            ],
        );

        // Flat path: map source directly as the argument value
        $nodes = [
            ...$nodes,
            ...$flatMapper->buildMappingNodes(
                variable('source'),
                variable('context'),
                variable('mappedValue'),
            ),
        ];

        $nodes[] = if_(
            condition: variable('context')->callMethod('containsErrors'),
            then: return_(value(null)),
        );

        $nodes[] = variable('values')->assign(
            array_([
                $argName => variable('mappedValue'),
            ]),
        )->asStatement();

        $nodes = [...$nodes, ...$defaultAndBuildNodes];
    }

    /**
     * Compile multi-argument builder (no isolation, direct return on error).
     *
     * @param non-empty-list<Node> $nodes
     */
    private function compileMultiArgBuilder(
        AnonymousClassNode &$class,
        TypeMapperFactory $typeMapperFactory,
        ObjectBuilder $builder,
        \CuyZ\Valinor\Mapper\Object\Arguments $arguments,
        array &$nodes,
        string $dumpedType,
    ): void {
        // Multi-argument case: delegate to ShapedArrayTypeMapper
        $shapedArrayType = $arguments->toShapedArray();
        $shapedMapper = $typeMapperFactory->forObjectArguments($shapedArrayType);
        $class = $shapedMapper->manipulateMapperClass($class, $typeMapperFactory);

        $nodes = [...$nodes, ...$shapedMapper->buildMappingNodes(variable('source'), variable('context'), variable('values'))];

        $nodes[] = if_(
            condition: variable('values')->equals(value(null)),
            then: return_(value(null)),
        );

        // Apply default values for optional arguments
        $defaultNodes = [];
        foreach ($arguments as $argument) {
            if (! $argument->isRequired()) {
                $defaultNodes[] = if_(
                    condition: negate(call('array_key_exists', [
                        value($argument->name()),
                        variable('values'),
                    ])),
                    then: variable('values')->key(value($argument->name()))->assign(
                        value($argument->defaultValue()),
                    )->asStatement(),
                );
            }
        }

        $tryBody = array_merge($defaultNodes, $builder->compile(variable('values')));
        $nodes[] = $this->buildConstructionTryNode($tryBody, $dumpedType);
    }

    /**
     * Prepares mappers for single-argument builders by computing the flattened type,
     * creating a shaped array mapper, and a flat mapper with attribute converters.
     *
     * @return array{shapedMapper: TypeMapper, flatMapper: TypeMapper, flattenedType: Type}
     */
    private function prepareSingleArgMappers(
        \CuyZ\Valinor\Mapper\Object\Argument $argument,
        TypeMapperFactory $typeMapperFactory,
    ): array {
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
        $shapedMapper = $typeMapperFactory->forObjectArguments($shapedArrayType);

        // Flat mapper for direct source mapping
        $flatMapper = $typeMapperFactory->for($flattenedType, attributes: $argument->attributes());

        return [
            'shapedMapper' => $shapedMapper,
            'flatMapper' => $flatMapper,
            'flattenedType' => $flattenedType,
        ];
    }

    /**
     * Wraps try body in try/catch handling for UserlandError and Message exceptions.
     *
     * @param non-empty-list<Node> $tryBody
     */
    private function buildConstructionTryNode(array $tryBody, string $dumpedType): Node
    {
        return try_(...$tryBody)->catches(
            UserlandError::class,
            new AddMessageNode(
                variable('context'),
                this()->access('exceptionFilter')->wrap()->call([variable('exception')->callMethod('getPrevious')]),
                $this->class->type->toString(),
                dumpValue(variable('source')),
                $dumpedType,
            ),
            return_(value(null)),
        )->catches(
            Message::class,
            new AddMessageNode(
                variable('context'),
                variable('exception'),
                $this->class->type->toString(),
                dumpValue(variable('source')),
                $dumpedType,
            ),
            return_(value(null)),
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
        $typeString = $this->class->type->toString();

        return self::buildMethodName('map_object', $typeString, $this->variantHashInput($typeString));
    }
}
