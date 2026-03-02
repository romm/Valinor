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
use function hash;
use function preg_replace;
use function strtolower;

/** @internal */
final class ShapedArrayTypeMapper implements TypeMapper
{
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

        $nodes = [];

        if ($settings->allowUndefinedValues) {
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: Node::variable('source')->assign(Node::value([]))->asExpression(),
            );
        } else {
            // Null check with "missing" error body
            $nodes[] = Node::if(
                condition: Node::variable('source')->equals(Node::value(null)),
                body: [
                    Node::variable('context')->callMethod('addMessage', [
                        new MessageNode(new SourceMustBeIterable(null)),
                        Node::value($this->type->toString()),
                        Node::value('*missing*'),
                    ])->asExpression(),
                    Node::return(Node::value(null)),
                ],
            );
        }

        // Non-iterable check with value error body (source is non-null here)
        $nodes[] = Node::if(
            condition: Node::negate(Node::functionCall('is_iterable', [Node::variable('source')])),
            body: [
                Node::variable('context')->callMethod('addMessage', [
                    new MessageNode(new SourceMustBeIterable('value')),
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

        // Apply key converters if configured
        if ($this->applyKeyConverters && $typeMapperFactory->hasKeyConverters()) {
            $keyConverterKeys = $typeMapperFactory->keyConverterKeys();

            // Build converter chain: apply each converter to the key
            $keyVarNode = Node::variable('ck');
            $converterNodes = [];

            foreach ($keyConverterKeys as $kcKey) {
                $converterNodes[] = $keyVarNode->assign(
                    Node::this()->access('constructorCallbacks')->key(Node::value($kcKey))->call([$keyVarNode]),
                )->asExpression();
            }

            $nodes[] = Node::variable('convertedSource')->assign(Node::value([]))->asExpression();
            $nodes[] = Node::variable('nameMap')->assign(Node::value([]))->asExpression();

            $tryBody = array_merge($converterNodes, [
                Node::variable('convertedSource')->key($keyVarNode)->assign(
                    Node::variable('origVal'),
                )->asExpression(),
                Node::variable('nameMap')->key($keyVarNode)->assign(
                    Node::functionCall('strval', [Node::variable('origKey')]),
                )->asExpression(),
            ]);

            $forEachBody = [
                $keyVarNode->assign(
                    Node::functionCall('strval', [Node::variable('origKey')]),
                )->asExpression(),
                Node::try(...$tryBody)->catches(
                    Exception::class,
                    // If exception is already a Message, use it directly; otherwise filter
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
            ];

            $nodes[] = Node::forEach(
                Node::variable('source'),
                'origKey',
                'origVal',
                $forEachBody,
            );

            $nodes[] = Node::variable('source')->assign(Node::variable('convertedSource'))->asExpression();
            $nodes[] = Node::variable('context')->callMethod('setNameMap', [
                Node::variable('nameMap'),
            ])->asExpression();
        }

        // Initialize result array
        $nodes[] = Node::variable('result')->assign(Node::value([]))->asExpression();

        // Process each element
        foreach ($this->type->elements as $key => $element) {
            $subMapper = $typeMapperFactory->for($element->type());

            // Wrap with attribute converters if the element has any
            $attrConverters = $typeMapperFactory->attributeConvertersFor($element->attributes(), $element->type());

            if ($attrConverters !== []) {
                $subMapper = new ConverterTypeMapperWrapper($element->type(), $subMapper, $attrConverters);
            }

            try {
                $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);
            } catch (CannotMapToPermissiveType) {
                throw CannotMapToPermissiveType::forType($element->type()->toString(), (string)$key);
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
                    $expectedSig = MappingContext::expectedSignatureForType($element->type());
                    $nodes[] = Node::if(
                        condition: Node::negate(Node::functionCall('array_key_exists', [
                            Node::value($key),
                            Node::variable('source'),
                        ])),
                        body: Node::variable('context')->callMethod('sub', [Node::value($keyStr)])->callMethod('addMessage', [
                            new MessageNode(MissingNodeValue::from($element->type())),
                            Node::value($element->type()->toString()),
                            Node::value('*missing*'),
                            Node::value($expectedSig),
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
                    condition: Node::negate(Node::variable('remaining')->equals(Node::value([]))),
                    body: Node::throw(
                        Node::class(CannotMapToPermissiveType::class)->callStaticMethod('forType', [
                            Node::value($permissiveTypeName),
                            Node::functionCall('strval', [
                                Node::functionCall('array_key_first', [Node::variable('remaining')]),
                            ]),
                        ]),
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
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($this->type->toString()));
        $hashInput = $this->type->toString();

        if (! $this->applyKeyConverters) {
            $hashInput .= '|no_kc';
        }

        return "map_shaped_array_{$slug}_" . hash('crc32', $hashInput);
    }
}
