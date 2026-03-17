<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotMapToPermissiveType;
use CuyZ\Valinor\Mapper\Tree\Exception\MissingNodeValue;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Mapper\Tree\Exception\UnexpectedKeyInSource;
use CuyZ\Valinor\Type\Types\MixedType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\UndefinedObjectType;
use CuyZ\Valinor\Type\VacantType;

use function array_flip;
use function array_keys;
use function CuyZ\Valinor\Compiler\{call, dumpValue, forEach_, if_, negate, newClass, param, return_, this, throw_, value, variable, when};

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

        $body = [
            // Handling null values
            // ====================
            //
            // The behaviour depends on the `allowUndefinedValues` setting.
            when(
                condition: $this->settings->allowUndefinedValues,

                // if ($source === null) {
                //     $source = [];
                // }
                then: if_(
                    condition: variable('source')->equals(value(null)),
                    then: variable('source')->assign(value([]))->asStatement(),
                ),

                // if ($source === null) {
                //     $context->addMessage('source must be iterable');
                // }
                else: if_(
                    condition: variable('source')->equals(value(null)),
                    then: [
                        new AddMessageNode(variable('context'), new SourceMustBeIterable(null), $this->type->toString(), value('*missing*'), $dumpedType),
                        return_(value(null)),
                    ],
                ),
            ),

            // Handling non-iterable
            // =====================
            //
            // if (! is_iterable($source)) {
            //     $context->addMessage('source must be iterable');
            // }
            if_(
                condition: negate(call('is_iterable', [variable('source')])),
                then: [
                    new AddMessageNode(variable('context'), new SourceMustBeIterable('value'), $this->type->toString(), dumpValue(variable('source')), $dumpedType),
                    return_(value(null)),
                ],
            ),

            // Converting to array
            // ====================
            //
            // if (! is_array($source)) {
            //     $source = iterator_to_array($source);
            // }
            if_(
                condition: negate(call('is_array', [variable('source')])),
                then: variable('source')->assign(
                    call('iterator_to_array', [variable('source')]),
                )->asStatement(),
            ),

            // Applying key converters
            // =======================
            when(
                condition: $this->applyKeyConverters && $typeMapperFactory->hasKeyConverters(),
                then: KeyConverterNodes::build($typeMapperFactory),
            ),

            // Initializing the result
            // =======================
            //
            // $result = [];
            variable('result')->assign(value([]))->asStatement(),

            // Processing each element
            // =======================
            //
            // For each element of the shaped array, generate mapping nodes and
            // mutate `$class` (registering sub-mapper methods).
            ...(function () use ($typeMapperFactory, &$class) {
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

                    yield if_(
                        condition: call('array_key_exists', [value($key), variable('source')]),

                        // Key exists in source: map the value through the sub-mapper.
                        //
                        // if (array_key_exists('some_key', $source)) {
                        //     $result['some_key'] = $this->mapSubType($source['some_key'], $context->sub('some_key'));
                        // }
                        then: $subMapper->buildMappingNodes(
                            variable('source')->key(value($key)),
                            variable('context')->callMethod('sub', [value($keyStr)]),
                            $elementTarget,
                        ),

                        // If the key does not exist in source *and* the element is not optional.
                        else: when(
                            ! $element->isOptional(),
                            then: when(
                                condition: $this->settings->allowUndefinedValues,

                                // `allowUndefinedValues` is on: pass null so the sub-mapper can cast/coerce it
                                //
                                // $result['some_key'] = $this->mapSubType(null, $context->sub('some_key'));
                                then: $subMapper->buildMappingNodes(
                                    value(null),
                                    variable('context')->callMethod('sub', [value($keyStr)]),
                                    $elementTarget,
                                ),

                                // `allowUndefinedValues` is off: the element is required but absent: report a
                                // missing-value error.
                                //
                                // $context->sub('some_key')->addMessage('missing value');
                                else: new AddMessageNode(
                                    variable('context')->callMethod('sub', [value($keyStr)]),
                                    MissingNodeValue::from($element->type()),
                                    $element->type()->toString(),
                                    value('*missing*'),
                                    $typeMapperFactory->dumpType($element->type()),
                                ),
                            )
                        )
                    );
                }
            })(),

            // Handling unsealed/extra keys
            // ============================
            //
            // For unsealed arrays, map remaining keys through the unsealed
            // type. For sealed arrays, detect unexpected extra keys. The
            // generator is used to both yield nodes and mutate `$class`
            // (registering the unsealed value mapper method).
            ...(function () use ($typeMapperFactory, &$class, $dumpedType) {
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

                    $definedKeys = array_keys($this->type->elements);

                    if ($isPermissive && ! $this->settings->allowPermissiveTypes) {
                        // Generate runtime code that throws when extra keys are encountered
                        yield variable('remaining')->assign(
                            call('array_diff_key', [
                                variable('source'),
                                value(array_flip($definedKeys)),
                            ]),
                        )->asStatement();

                        yield if_(
                            condition: negate(variable('remaining')->equals(value([]))->wrap()),
                            then: throw_(
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
                        // Map remaining key-value pairs through the unsealed type's sub-type
                        $valueMapper = $typeMapperFactory->for($unsealedType->subType());
                        $class = $valueMapper->manipulateMapperClass($class, $typeMapperFactory);

                        yield variable('remaining')->assign(
                            call('array_diff_key', [
                                variable('source'),
                                value(array_flip($definedKeys)),
                            ]),
                        )->asStatement();

                        yield forEach_(
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

                    yield variable('extraKeys')->assign(
                        call('array_diff_key', [
                            variable('source'),
                            value(array_flip($definedKeys)),
                        ]),
                    )->asStatement();

                    yield forEach_(
                        variable('extraKeys'),
                        'extraKey',
                        'extraValue',
                        if_(
                            condition: negate(variable('context')->callMethod('isAllowedSuperfluousKey', [
                                call('strval', [variable('extraKey')]),
                            ])),
                            then: new AddMessageNode(
                                variable('context')->callMethod('sub', [call('strval', [variable('extraKey')])]),
                                new UnexpectedKeyInSource(),
                                $this->type->toString(),
                                dumpValue(variable('extraValue')),
                                $dumpedType,
                            ),
                        ),
                    );
                }
            })(),

            // Checking if errors occurred
            // ===========================
            //
            // if ($context->containsErrors()) {
            //     return null;
            // }
            if_(
                condition: variable('context')->callMethod('containsErrors'),
                then: return_(value(null)),
            ),

            // Returning the result
            // ====================
            //
            // return $result;
            return_(variable('result')),
        ];

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: '?' . $this->type->nativeType()->toString(),
            body: $body,
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
