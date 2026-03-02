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
use CuyZ\Valinor\Mapper\Tree\Exception\CannotMapToPermissiveType;
use CuyZ\Valinor\Mapper\Tree\Exception\MissingNodeValue;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Mapper\Tree\Exception\UnexpectedKeyInSource;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use Exception;
use CuyZ\Valinor\Type\Types\MixedType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\UndefinedObjectType;
use CuyZ\Valinor\Type\VacantType;
use CuyZ\Valinor\Utility\ValueDumper;

use function array_keys;

/** @internal */
final class ShapedArrayTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    public function __construct(
        private ShapedArrayType $type,
        private bool $applyKeyConverters = true,
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

        // Compute effective type signature for error messages
        $dumpedType = $typeMapperFactory->dumpType($this->type);

        $nodes = IterableValidationNodes::build($settings, $this->type, $dumpedType);

        // Convert to array if needed
        $nodes[] = Node::if(
            condition: Node::negate(Node::functionCall('is_array', [Node::variable('source')])),
            body: Node::variable('source')->assign(
                Node::functionCall('iterator_to_array', [Node::variable('source')]),
            )->asExpression(),
        );

        // Apply key converters if configured
        if ($this->applyKeyConverters && $typeMapperFactory->hasKeyConverters()) {
            $nodes = [...$nodes, ...KeyConverterNodes::build($typeMapperFactory, wrapInArrayCheck: false)];
        }

        // Initialize result array
        $nodes[] = Node::variable('result')->assign(Node::value([]))->asExpression();

        // Process each element
        foreach ($this->type->elements as $key => $element) {
            $subMapper = $typeMapperFactory->for($element->type());

            // Wrap with attribute converters if the element has any
            $attrConverters = $typeMapperFactory->converterAnalyzer()->attributeConvertersFor($element->attributes(), $element->type(), $typeMapperFactory->callbackRegistry());

            if ($attrConverters !== []) {
                $subMapper = new ConverterTypeMapperWrapper($element->type(), $subMapper, $attrConverters);
            }

            try {
                $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
            } catch (CannotMapToPermissiveType) {
                throw new CannotMapToPermissiveType($element->type()->toString(), (string)$key);
            }

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
                // Required element: map value if exists, otherwise add missing error
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

                if ($settings->allowUndefinedValues) {
                    // When undefined values are allowed, pass null through sub-mapper
                    // (it handles null→default conversion, e.g. null→[] for lists)
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
                } else {
                    // When undefined values are NOT allowed, add a proper missing value error
                    $dumpedElementType = $typeMapperFactory->dumpType($element->type());
                    $nodes[] = Node::if(
                        condition: Node::negate(Node::functionCall('array_key_exists', [
                            Node::value($key),
                            Node::variable('source'),
                        ])),
                        body: Node::variable('context')->callMethod('sub', [Node::value($keyStr)])->callMethod('addMessage', [
                            new MessageNode(MissingNodeValue::from($element->type())),
                            Node::value($element->type()->toString()),
                            Node::value('*missing*'),
                            Node::value($dumpedElementType),
                        ])->asExpression(),
                    );
                }
            }
        }

        // Handle unsealed array: process remaining keys through unsealed type
        if ($this->type->isUnsealed) {
            $unsealedType = $this->type->unsealedType();

            // Check for permissive types in unsealed portion — generate runtime check
            // so the actual key name appears in the exception path
            $isPermissive = false;
            $permissiveTypeName = 'mixed';

            if ($unsealedType instanceof VacantType) {
                $isPermissive = true;
            } else {
                $subType = $unsealedType->subType();
                if ($subType instanceof MixedType) {
                    $isPermissive = true;
                } elseif ($subType instanceof UndefinedObjectType) {
                    $isPermissive = true;
                    $permissiveTypeName = 'object';
                }
            }

            if ($isPermissive && ! $settings->allowPermissiveTypes) {
                // Generate runtime code that throws when extra keys are encountered
                $definedKeys = array_keys($this->type->elements);
                $nodes[] = Node::variable('remaining')->assign(
                    Node::functionCall('array_diff_key', [
                        Node::variable('source'),
                        Node::functionCall('array_flip', [Node::value($definedKeys)]),
                    ]),
                )->asExpression();

                $nodes[] = Node::if(
                    condition: Node::negate(Node::variable('remaining')->equals(Node::value([]))->wrap()),
                    body: Node::throw(
                        Node::newClass(
                            CannotMapToPermissiveType::class,
                            Node::value($permissiveTypeName),
                            Node::functionCall('strval', [
                                Node::functionCall('array_key_first', [Node::variable('remaining')]),
                            ]),
                        ),
                    )->asExpression(),
                );
            } elseif (! $unsealedType instanceof VacantType) {
                // Compute remaining keys
                $definedKeys = array_keys($this->type->elements);
                $nodes[] = Node::variable('remaining')->assign(
                    Node::functionCall('array_diff_key', [
                        Node::variable('source'),
                        Node::functionCall('array_flip', [Node::value($definedKeys)]),
                    ]),
                )->asExpression();

                // Map remaining key-value pairs through the unsealed type's sub-type
                $valueMapper = $typeMapperFactory->for($unsealedType->subType());
                $class = $valueMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

                $nodes[] = Node::forEach(
                    Node::variable('remaining'),
                    'remainingKey',
                    'remainingValue',
                    Node::variable('result')->key(Node::variable('remainingKey'))->assign(
                        $valueMapper->formatValueNode(
                            Node::variable('remainingValue'),
                            Node::variable('context')->callMethod('sub', [
                                Node::functionCall('strval', [Node::variable('remainingKey')]),
                            ]),
                        ),
                    )->asExpression(),
                );
            }
        } elseif (! $settings->allowSuperfluousKeys) {
            // Sealed array: detect extra keys
            $definedKeys = array_keys($this->type->elements);
            $nodes[] = Node::variable('extraKeys')->assign(
                Node::functionCall('array_diff_key', [
                    Node::variable('source'),
                    Node::functionCall('array_flip', [Node::value($definedKeys)]),
                ]),
            )->asExpression();

            $nodes[] = Node::forEach(
                Node::variable('extraKeys'),
                'extraKey',
                'extraValue',
                Node::if(
                    condition: Node::negate(Node::variable('context')->callMethod('isAllowedSuperfluousKey', [
                        Node::functionCall('strval', [Node::variable('extraKey')]),
                    ])),
                    body: Node::variable('context')->callMethod('sub', [
                        Node::functionCall('strval', [Node::variable('extraKey')]),
                    ])->callMethod('addMessage', [
                        new MessageNode(new UnexpectedKeyInSource()),
                        Node::value($this->type->toString()),
                        Node::class(ValueDumper::class)->callStaticMethod('dump', [Node::variable('extraValue')]),
                        Node::value($dumpedType),
                    ])->asExpression(),
                ),
            );
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
        $hashInput = $this->type->toString();

        if (! $this->applyKeyConverters) {
            $hashInput .= '|no_kc';
        }

        return self::buildMethodName('map_shaped_array', $this->type->toString(), $hashInput);
    }
}
