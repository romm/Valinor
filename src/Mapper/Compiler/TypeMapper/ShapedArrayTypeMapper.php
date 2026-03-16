<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotMapToPermissiveType;
use CuyZ\Valinor\Mapper\Tree\Exception\MissingNodeValue;
use CuyZ\Valinor\Mapper\Tree\Exception\UnexpectedKeyInSource;
use CuyZ\Valinor\Type\Types\MixedType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\UndefinedObjectType;
use CuyZ\Valinor\Type\VacantType;
use CuyZ\Valinor\Utility\ValueDumper;
use Exception;

use function array_flip;
use function array_keys;
use function CuyZ\Valinor\Compiler\{call, className, forEach_, if_, negate, newClass, param, return_, this, throw_, value, variable};

/** @internal */
final class ShapedArrayTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    public function __construct(
        private ShapedArrayType $type,
        private Settings $settings,
        private bool $applyKeyConverters = true,
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

        // Register a placeholder method to prevent infinite recursion.
        $class = $class->withMethod($methodName);

        // Compute effective type signature for error messages
        $dumpedType = $typeMapperFactory->dumpType($this->type);

        $nodes = IterableValidationNodes::build($this->settings, $this->type, $dumpedType);

        // Convert to array if needed
        $nodes[] = if_(
            condition: negate(call('is_array', [variable('source')])),
            body: variable('source')->assign(
                call('iterator_to_array', [variable('source')]),
            )->asStatement(),
        );

        // Apply key converters if configured
        if ($this->applyKeyConverters && $typeMapperFactory->hasKeyConverters()) {
            $nodes = [...$nodes, ...KeyConverterNodes::build($typeMapperFactory, wrapInArrayCheck: false)];
        }

        // Initialize result array
        $nodes[] = variable('result')->assign(value([]))->asStatement();

        // Process each element
        foreach ($this->type->elements as $key => $element) {
            $subMapper = $typeMapperFactory->for($element->type());

            // Wrap with attribute converters if the element has any
            $attrConverters = $typeMapperFactory->converterAnalyzer()->attributeConvertersFor($element->attributes(), $element->type());

            if ($attrConverters !== []) {
                $subMapper = new ConverterTypeMapperWrapper($element->type(), $subMapper, $attrConverters);
            }

            try {
                $class = $subMapper->manipulateMapperClass($class, $typeMapperFactory);
            } catch (CannotMapToPermissiveType) {
                throw new CannotMapToPermissiveType($element->type()->toString(), (string)$key);
            }

            $keyStr = (string)$key;

            $elementTarget = variable('result')->key(value($key));

            if ($element->isOptional()) {
                // Optional element: only process if key exists
                $nodes[] = if_(
                    condition: call('array_key_exists', [
                        value($key),
                        variable('source'),
                    ]),
                    body: $subMapper->buildMappingNodes(
                        variable('source')->key(value($key)),
                        variable('context')->callMethod('sub', [value($keyStr)]),
                        $elementTarget,
                    ),
                );
            } else {
                // Required element: map value if exists, otherwise handle missing
                $ifBody = $subMapper->buildMappingNodes(
                    variable('source')->key(value($key)),
                    variable('context')->callMethod('sub', [value($keyStr)]),
                    $elementTarget,
                );

                if ($this->settings->allowUndefinedValues) {
                    // When undefined values are allowed, pass null through sub-mapper
                    // (it handles null→default conversion, e.g. null→[] for lists)
                    $elseBody = $subMapper->buildMappingNodes(
                        value(null),
                        variable('context')->callMethod('sub', [value($keyStr)]),
                        $elementTarget,
                    );
                } else {
                    // When undefined values are NOT allowed, add a proper missing value error
                    $dumpedElementType = $typeMapperFactory->dumpType($element->type());
                    $elseBody = variable('context')->callMethod('sub', [value($keyStr)])->callMethod('addMessage', [
                        new MessageNode(MissingNodeValue::from($element->type())),
                        value($element->type()->toString()),
                        value('*missing*'),
                        value($dumpedElementType),
                    ])->asStatement();
                }

                $nodes[] = if_(
                    condition: call('array_key_exists', [
                        value($key),
                        variable('source'),
                    ]),
                    body: $ifBody,
                )->else($elseBody);
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

            if ($isPermissive && ! $this->settings->allowPermissiveTypes) {
                // Generate runtime code that throws when extra keys are encountered
                $definedKeys = array_keys($this->type->elements);
                $nodes[] = variable('remaining')->assign(
                    call('array_diff_key', [
                        variable('source'),
                        value(array_flip($definedKeys)),
                    ]),
                )->asStatement();

                $nodes[] = if_(
                    condition: negate(variable('remaining')->equals(value([]))->wrap()),
                    body: throw_(
                        newClass(
                            CannotMapToPermissiveType::class,
                            value($permissiveTypeName),
                            call('strval', [
                                call('array_key_first', [variable('remaining')]),
                            ]),
                        ),
                    )->asStatement(),
                );
            } elseif (! $unsealedType instanceof VacantType) {
                // Compute remaining keys
                $definedKeys = array_keys($this->type->elements);
                $nodes[] = variable('remaining')->assign(
                    call('array_diff_key', [
                        variable('source'),
                        value(array_flip($definedKeys)),
                    ]),
                )->asStatement();

                // Map remaining key-value pairs through the unsealed type's sub-type
                $valueMapper = $typeMapperFactory->for($unsealedType->subType());
                $class = $valueMapper->manipulateMapperClass($class, $typeMapperFactory);

                $nodes[] = forEach_(
                    variable('remaining'),
                    'remainingKey',
                    'remainingValue',
                    $valueMapper->buildMappingNodes(
                        variable('remainingValue'),
                        variable('context')->callMethod('sub', [
                            call('strval', [variable('remainingKey')]),
                        ]),
                        variable('result')->key(variable('remainingKey')),
                    ),
                );
            }
        } elseif (! $this->settings->allowSuperfluousKeys) {
            // Sealed array: detect extra keys
            $definedKeys = array_keys($this->type->elements);
            $nodes[] = variable('extraKeys')->assign(
                call('array_diff_key', [
                    variable('source'),
                    value(array_flip($definedKeys)),
                ]),
            )->asStatement();

            $nodes[] = forEach_(
                variable('extraKeys'),
                'extraKey',
                'extraValue',
                if_(
                    condition: negate(variable('context')->callMethod('isAllowedSuperfluousKey', [
                        call('strval', [variable('extraKey')]),
                    ])),
                    body: variable('context')->callMethod('sub', [
                        call('strval', [variable('extraKey')]),
                    ])->callMethod('addMessage', [
                        new MessageNode(new UnexpectedKeyInSource()),
                        value($this->type->toString()),
                        className(ValueDumper::class)->callStaticMethod('dump', [variable('extraValue')]),
                        value($dumpedType),
                    ])->asStatement(),
                ),
            );
        }

        // Check for errors after processing all elements
        $nodes[] = if_(
            condition: variable('context')->callMethod('containsErrors'),
            body: return_(value(null)),
        );

        $nodes[] = return_(variable('result'));

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: '?array',
            body: $nodes,
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
