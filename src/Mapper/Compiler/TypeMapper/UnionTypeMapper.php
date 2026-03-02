<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\InterfacePassthroughTypeMapper;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveObjectType;
use CuyZ\Valinor\Type\ClassType;
use CuyZ\Valinor\Type\FixedType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\EnumType;
use CuyZ\Valinor\Type\Types\InterfaceType;
use CuyZ\Valinor\Type\Types\NullType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\UnionType;
use CuyZ\Valinor\Type\VacantType;
use CuyZ\Valinor\Utility\TypeHelper;

use function array_map;
use function count;
use function hash;
use function implode;
use function preg_replace;
use function strtolower;

/** @internal */
final class UnionTypeMapper implements TypeMapper
{
    public function __construct(
        private UnionType $type,
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

        // Register a placeholder method to prevent infinite recursion.
        $class = $class->withMethods(Node::method($methodName));

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
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: Node::return(Node::value(null)),
            );
        }

        // Expected signature for unions — use TypeDumper for readable types
        $expectedSignature = implode(', ', array_map(
            fn ($t) => $typeMapperFactory->dumpType($t),
            $this->type->types(),
        ));

        if (count($nonNullTypes) === 1 && ! $hasNull) {
            // Only one type total (no null): map directly without isolation
            $subType = $nonNullTypes[0];

            try {
                $subMapper = $typeMapperFactory->for($subType);
                $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
            } catch (CannotResolveObjectType) {
                $nodes[] = Node::return(Node::value(null));

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

            $nodes[] = Node::return(
                $subMapper->formatValueNode(
                    Node::variable('source'),
                    Node::variable('context'),
                ),
            );
        } else if (count($nonNullTypes) === 1) {
            // Nullable single type: use isolation so error becomes union-level
            $subType = $nonNullTypes[0];

            try {
                $subMapper = $typeMapperFactory->for($subType);
                $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
            } catch (CannotResolveObjectType) {
                $nodes[] = Node::return(Node::value(null));

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

            // Try mapping in isolation
            $nodes[] = Node::variable('subContext')->assign(
                Node::variable('context')->callMethod('isolate'),
            )->asExpression();

            $nodes[] = Node::variable('subResult')->assign(
                $subMapper->formatValueNode(
                    Node::variable('source'),
                    Node::variable('subContext'),
                ),
            )->asExpression();

            // If no errors, return the result
            $nodes[] = Node::if(
                condition: Node::negate(Node::variable('subContext')->callMethod('containsErrors')),
                body: Node::return(Node::variable('subResult')),
            );

            // Failed — decide whether to propagate specific errors or show union error.
            // In the runtime, there's also an implicit null mismatch error (priority 1).
            // If the non-null type has higher priority (>1), its error wins.
            // If equal priority (=1, e.g. scalar|null), show union-level error.
            if (TypeHelper::typePriority($subType) > 1) {
                // Higher priority type: propagate its specific error
                $nodes[] = Node::variable('context')->callMethod('mergeFrom', [
                    Node::variable('subContext'),
                ])->asExpression();
                $nodes[] = Node::return(Node::variable('subResult'));
            } else {
                // Equal priority: show union-level error
                $nodes[] = Node::variable('context')->callMethod('addMessage', [
                    Node::newClass(\CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveTypeFromUnion::class, Node::variable('source')),
                    Node::value($this->type->toString()),
                    Node::class(\CuyZ\Valinor\Utility\ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                    Node::value($expectedSignature),
                ])->asExpression();
                $nodes[] = Node::return(Node::value(null));
            }
        } else {
            // Multiple non-null types: try each in isolated context, collect results,
            // then resolve using the prioritization logic
            $nodes[] = Node::variable('candidates')->assign(Node::value([]))->asExpression();
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
                $nodes[] = Node::variable($subCtxVar)->assign(
                    Node::variable('context')->callMethod('isolate'),
                )->asExpression();

                // Try mapping
                $nodes[] = Node::variable($subResultVar)->assign(
                    $subMapper->formatValueNode(
                        Node::variable('source'),
                        Node::variable($subCtxVar),
                    ),
                )->asExpression();

                // Add to candidates using compile-time sequential index
                $nodes[] = Node::variable('candidates')->key(Node::value($candidateIdx))->assign(
                    Node::array([
                        'result' => Node::variable($subResultVar),
                        'context' => Node::variable($subCtxVar),
                        'category' => Node::value($category),
                        'errorPriority' => Node::value($errorPriority),
                        'scalarPriority' => Node::value($scalarPriority),
                        'children' => Node::value($children),
                    ]),
                )->asExpression();
                $candidateIdx++;
            }

            // Resolve using the union resolution logic
            $nodes[] = Node::return(
                Node::variable('context')->callMethod('resolveUnion', [
                    Node::variable('candidates'),
                    Node::variable('source'),
                    Node::value($this->type->toString()),
                    Node::value($expectedSignature),
                ]),
            );
        }

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
            } catch (\Throwable) {
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
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->type->toString()));

        return "map_union_{$slug}_" . hash('crc32', $this->type->toString());
    }
}
