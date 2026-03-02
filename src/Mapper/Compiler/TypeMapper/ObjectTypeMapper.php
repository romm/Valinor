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
use CuyZ\Valinor\Mapper\Object\ObjectBuilder;
use CuyZ\Valinor\Type\CompositeTraversableType;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\ShapedArrayElement;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\StringValueType;
use CuyZ\Valinor\Type\Types\UnionType;

use function array_filter;
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

        foreach ($this->builders as $builder) {
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
                    ),
                ]);
                $shapedMapper = new ShapedArrayTypeMapper($shapedArrayType);
                $class = $shapedMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

                // Flat mapper for direct source mapping
                $flatMapper = $typeMapperFactory->for($flattenedType);
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
                $defaultAndBuildNodes = [...$defaultAndBuildNodes, ...$builder->compile(Node::variable('values'))];

                // Keyed path condition: source is array with the argument name as key
                $keyedCondition = Node::functionCall('is_array', [Node::variable('source')])
                    ->and(Node::functionCall('array_key_exists', [
                        Node::value($argName),
                        Node::variable('source'),
                    ]));

                if ($argType instanceof CompositeTraversableType) {
                    $keyedCondition = $keyedCondition->and(
                        Node::functionCall('count', [Node::variable('source')])->equals(Node::value(1)),
                    );
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
                $shapedMapper = new ShapedArrayTypeMapper($shapedArrayType);
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

                $nodes = [...$nodes, ...$builder->compile(Node::variable('values'))];
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
     * @return non-empty-string
     */
    private function methodName(): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->class->type->toString()));

        return "map_object_{$slug}_" . hash('crc32', $this->class->type->toString());
    }
}
