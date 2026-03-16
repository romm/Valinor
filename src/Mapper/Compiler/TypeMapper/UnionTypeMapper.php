<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Compiler\UnionResolver;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveObjectType;
use CuyZ\Valinor\Type\ClassType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\InterfaceType;
use CuyZ\Valinor\Type\Types\NullType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\UnionType;
use CuyZ\Valinor\Utility\TypeHelper;

use Throwable;

use function array_map;
use function count;
use function CuyZ\Valinor\Compiler\{array_, className, if_, negate, newClass, param, return_, this, value, variable};
use function implode;
/** @internal */
final class UnionTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    public function __construct(
        private UnionType $type,
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

        // Register a placeholder method to prevent infinite recursion.
        $class = $class->withMethod($methodName);

        $nodes = [];

        $hasNull = false;
        $nonNullTypes = [];

        foreach ($this->type->types() as $subType) {
            if ($subType instanceof NullType) {
                $hasNull = true;
            } else {
                $nonNullTypes[] = $subType;
            }
        }

        // Null fast-path: if source is null and NullType is in the union, return null immediately
        if ($hasNull) {
            $nodes[] = if_(
                condition: variable('source')->equals(value(null)),
                body: return_(value(null)),
            );
        }

        // Expected signature for unions — use TypeDumper for readable types
        $expectedSignature = implode(', ', array_map(
            $typeMapperFactory->dumpType(...),
            $this->type->types(),
        ));

        if (count($nonNullTypes) === 1 && ! $hasNull) {
            // Only one type total (no null): map directly without isolation
            $subType = $nonNullTypes[0];

            try {
                $subMapper = $typeMapperFactory->for($subType);
                $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
            } catch (CannotResolveObjectType) {
                return $this->buildUnresolvableMethod($class, $methodName, $nodes);
            }

            $nodes[] = variable('result')->assign(value(null))->asStatement();

            $nodes = [
                ...$nodes,
                ...$subMapper->buildMappingNodes(
                    variable('source'),
                    variable('context'),
                    variable('result'),
                ),
            ];

            $nodes[] = return_(variable('result'));
        } elseif (count($nonNullTypes) === 1) {
            // Nullable single type: use isolation so error becomes union-level
            $subType = $nonNullTypes[0];

            try {
                $subMapper = $typeMapperFactory->for($subType);
                $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
            } catch (CannotResolveObjectType) {
                return $this->buildUnresolvableMethod($class, $methodName, $nodes);
            }

            // Try mapping in isolation
            $nodes[] = variable('subContext')->assign(
                variable('context')->callMethod('isolate'),
            )->asStatement();

            $nodes[] = variable('subResult')->assign(value(null))->asStatement();

            $nodes = [
                ...$nodes,
                ...$subMapper->buildMappingNodes(
                    variable('source'),
                    variable('subContext'),
                    variable('subResult'),
                ),
            ];

            // If no errors, return the result
            $nodes[] = if_(
                condition: negate(variable('subContext')->callMethod('containsErrors')),
                body: return_(variable('subResult')),
            );

            // Failed — decide whether to propagate specific errors or show union error.
            // In the runtime, there's also an implicit null mismatch error (priority 1).
            // If the non-null type has higher priority (>1), its error wins.
            // If equal priority (=1, e.g. scalar|null), show union-level error.
            if (TypeHelper::typePriority($subType) > 1) {
                // Higher priority type: propagate its specific error
                $nodes[] = variable('context')->callMethod('mergeFrom', [
                    variable('subContext'),
                ])->asStatement();
                $nodes[] = return_(variable('subResult'));
            } else {
                // Equal priority: show union-level error
                $nodes[] = variable('context')->callMethod('addMessage', [
                    newClass(\CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveTypeFromUnion::class, variable('source')),
                    value($this->type->toString()),
                    className(\CuyZ\Valinor\Utility\ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
                    value($expectedSignature),
                ])->asStatement();
                $nodes[] = return_(value(null));
            }
        } else {
            // Multiple non-null types: try each in isolated context, collect results,
            // then resolve using the prioritization logic
            $nodes[] = variable('candidates')->assign(value([]))->asStatement();
            $candidateIdx = 0;

            foreach ($nonNullTypes as $i => $subType) {
                try {
                    $subMapper = $typeMapperFactory->for($subType);

                    // Skip unresolvable interfaces in union context
                    if ($subMapper instanceof InterfacePassthroughTypeMapper) {
                        continue;
                    }

                    $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
                } catch (CannotResolveObjectType) {
                    // Interface with no implementation — skip this type
                    continue;
                }

                $subCtxVar = "subContext_{$i}";
                $subResultVar = "subResult_{$i}";

                // Compute compile-time metadata for this type
                $category = $this->typeCategory($subType);
                $errorPriority = TypeHelper::typePriority($subType);
                $scalarPriority = ($subType instanceof ScalarType) ? TypeHelper::scalarTypePriority($subType) : 0;
                $children = $this->childrenCount($subType, $typeMapperFactory);

                // Create isolated context
                $nodes[] = variable($subCtxVar)->assign(
                    variable('context')->callMethod('isolate'),
                )->asStatement();

                // Try mapping
                $nodes[] = variable($subResultVar)->assign(value(null))->asStatement();

                $nodes = [
                    ...$nodes,
                    ...$subMapper->buildMappingNodes(
                        variable('source'),
                        variable($subCtxVar),
                        variable($subResultVar),
                    ),
                ];

                // Add to candidates using compile-time sequential index
                $nodes[] = variable('candidates')->key(value($candidateIdx))->assign(
                    array_([
                        'result' => variable($subResultVar),
                        'context' => variable($subCtxVar),
                        'category' => value($category),
                        'errorPriority' => value($errorPriority),
                        'scalarPriority' => value($scalarPriority),
                        'children' => value($children),
                    ]),
                )->asStatement();
                $candidateIdx++;
            }

            // Resolve using the union resolution logic
            $nodes[] = variable('resolver')->assign(
                newClass(UnionResolver::class),
            )->asStatement();

            $nodes[] = return_(
                variable('resolver')->callMethod('resolve', [
                    variable('context'),
                    variable('candidates'),
                    variable('source'),
                    value($this->type->toString()),
                    value($expectedSignature),
                ]),
            );
        }

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

    private function buildUnresolvableMethod(
        AnonymousClassNode $class,
        string $methodName,
        array $nodes,
    ): AnonymousClassNode {
        $nodes[] = return_(value(null));

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
    private function typeCategory(Type $type): string
    {
        if ($type instanceof InterfaceType || $type instanceof ClassType || $type instanceof ShapedArrayType) {
            return 'struct';
        }

        if ($type instanceof ScalarType) {
            return 'scalar';
        }

        return 'other';
    }

    private function childrenCount(Type $type, TypeMapperFactory $typeMapperFactory): int
    {
        if ($type instanceof ShapedArrayType) {
            return count($type->elements);
        }

        if ($type instanceof ClassType) {
            try {
                $mapper = $typeMapperFactory->for($type);
                if ($mapper instanceof ObjectTypeMapper) {
                    return $mapper->argumentCount();
                }
            } catch (Throwable) {
                // Ignore errors
            }
        }

        return 0;
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('map_union', $this->type->toString());
    }
}
