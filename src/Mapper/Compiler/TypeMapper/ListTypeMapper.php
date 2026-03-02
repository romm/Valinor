<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidIterableKeyType;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceIsEmptyList;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Type\Types\ListType;
use CuyZ\Valinor\Type\Types\NonEmptyListType;
use CuyZ\Valinor\Utility\ValueDumper;

/** @internal */
final class ListTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    public function __construct(
        private ListType|NonEmptyListType $type,
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

        $subMapper = $typeMapperFactory->for($this->type->subType());
        $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

        $nodes = IterableValidationNodes::build($settings, $this->type);

        // Check non-empty for NonEmptyListType
        if ($this->type instanceof NonEmptyListType) {
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value([])),
                body: [
                    Node::variable('context')->callMethod('addMessage', [
                        new MessageNode(new SourceIsEmptyList()),
                        Node::value($this->type->toString()),
                        Node::value('[]'),
                    ])->asExpression(),
                    Node::return(Node::value(null)),
                ],
            );
        }

        // Initialize result array
        $nodes[] = Node::variable('result')->assign(Node::value([]))->asExpression();

        // forEach loop over source: validate keys and map values
        $forEachBody = [];

        // Validate key type
        $forEachBody[] = Node::if(
            condition: Node::negate(Node::functionCall('is_string', [Node::variable('key')]))
                ->and(Node::negate(Node::functionCall('is_int', [Node::variable('key')]))),
            body: Node::throw(Node::newClass(InvalidIterableKeyType::class, Node::variable('key'), Node::variable('context')->access('path')))->asExpression(),
        );

        // Map value through sub-type mapper
        $forEachBody[] = Node::variable('result')->key(Node::variable('key'))->assign(
            $subMapper->formatValueNode(
                Node::variable('value'),
                Node::variable('context')->callMethod('sub', [
                    Node::functionCall('strval', [Node::variable('key')]),
                ]),
            ),
        )->asExpression();

        $nodes[] = Node::forEach(
            value: Node::variable('source'),
            key: 'key',
            item: 'value',
            body: $forEachBody,
        );

        // Check for errors
        $nodes[] = Node::if(
            condition: Node::variable('context')->callMethod('containsErrors'),
            body: Node::return(Node::value(null)),
        );

        // Return array_values to ensure sequential list keys
        $nodes[] = Node::return(
            Node::functionCall('array_values', [Node::variable('result')]),
        );

        return $class->withMethods(
            Node::method($methodName)
                ->witParameters(
                    Node::parameterDeclaration('source', 'mixed'),
                    Node::parameterDeclaration('context', MappingContext::class),
                )
                ->withReturnType('?array')
                ->withBody(...$nodes),
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('map_list', $this->type->toString());
    }
}
