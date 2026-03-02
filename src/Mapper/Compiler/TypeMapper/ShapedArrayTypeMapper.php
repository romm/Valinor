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
use CuyZ\Valinor\Mapper\Tree\Exception\MissingNodeValue;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Utility\ValueDumper;

use function hash;
use function preg_replace;
use function strtolower;

/** @internal */
final class ShapedArrayTypeMapper implements TypeMapper
{
    public function __construct(
        private ShapedArrayType $type,
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

        $nodes = [];

        if ($settings->allowUndefinedValues) {
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: Node::variable('source')->assign(Node::value([]))->asExpression(),
            );
        }

        // Check source is iterable
        $nodes[] = Node::if(
            condition: Node::negate(Node::functionCall('is_iterable', [Node::variable('source')])),
            body: [
                Node::variable('context')->callMethod('addMessage', [
                    new MessageNode(new SourceMustBeIterable(null)),
                    Node::value($this->type->toString()),
                    Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('source')]),
                ])->asExpression(),
                Node::return(Node::value(null)),
            ],
        );

        // Convert to array if needed
        $nodes[] = Node::if(
            condition: Node::negate(Node::functionCall('is_array', [Node::variable('source')])),
            body: Node::variable('source')->assign(
                Node::functionCall('iterator_to_array', [Node::variable('source')]),
            )->asExpression(),
        );

        // Initialize result array
        $nodes[] = Node::variable('result')->assign(Node::value([]))->asExpression();

        // Process each element
        foreach ($this->type->elements as $key => $element) {
            $subMapper = $typeMapperFactory->for($element->type());
            $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

            $keyStr = (string)$key;

            if ($element->isOptional()) {
                // Optional element: only process if key exists
                $nodes[] = Node::if(
                    condition: Node::functionCall('array_key_exists', [
                        Node::value($key),
                        Node::variable('source'),
                    ]),
                    body: Node::variable('result')->key(Node::value($key))->assign(
                        $subMapper->formatValueNode(
                            Node::variable('source')->key(Node::value($key)),
                            Node::variable('context')->callMethod('sub', [Node::value($keyStr)]),
                        ),
                    )->asExpression(),
                );
            } else {
                // Required element: map value if exists, otherwise map null (sub-mapper produces error)
                $nodes[] = Node::if(
                    condition: Node::functionCall('array_key_exists', [
                        Node::value($key),
                        Node::variable('source'),
                    ]),
                    body: Node::variable('result')->key(Node::value($key))->assign(
                        $subMapper->formatValueNode(
                            Node::variable('source')->key(Node::value($key)),
                            Node::variable('context')->callMethod('sub', [Node::value($keyStr)]),
                        ),
                    )->asExpression(),
                );

                // If key doesn't exist, pass null through mapper (will trigger error for required types)
                $nodes[] = Node::if(
                    condition: Node::negate(Node::functionCall('array_key_exists', [
                        Node::value($key),
                        Node::variable('source'),
                    ])),
                    body: Node::variable('result')->key(Node::value($key))->assign(
                        $subMapper->formatValueNode(
                            Node::value(null),
                            Node::variable('context')->callMethod('sub', [Node::value($keyStr)]),
                        ),
                    )->asExpression(),
                );
            }
        }

        // Check for errors after processing all elements
        $nodes[] = Node::if(
            condition: Node::variable('context')->callMethod('containsErrors'),
            body: Node::return(Node::value(null)),
        );

        $nodes[] = Node::return(Node::variable('result'));

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
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->type->toString()));

        return "map_shaped_array_{$slug}_" . hash('crc32', $this->type->toString());
    }
}
