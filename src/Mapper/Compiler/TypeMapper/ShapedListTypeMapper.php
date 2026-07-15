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
use CuyZ\Valinor\Type\Types\ShapedListType;
use CuyZ\Valinor\Type\Types\UndefinedObjectType;
use CuyZ\Valinor\Type\VacantType;

use function array_flip;
use function array_keys;
use function count;
use function CuyZ\Valinor\Compiler\{call, dumpValue, forEach_, if_, logicalOr, negate, newClass, param, postIncrement, return_, this, throw_, value, variable, when};

/** @internal */
final class ShapedListTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;

    public function __construct(
        private ShapedListType $type,
        private Settings $settings,
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

            // Initializing the result
            // =======================
            //
            // $result = [];
            variable('result')->assign(value([]))->asStatement(),

            // Processing each element
            // =======================
            //
            // Elements of a shaped list are positional: their keys are the
            // successive integers starting at 0. The generator is used to both
            // yield nodes and mutate `$class` (registering sub-mapper methods).
            ...(function () use ($typeMapperFactory, &$class) {
                foreach ($this->type->elements as $key => $element) {
                    $subMapper = $typeMapperFactory->for($element->type(), attributes: $element->attributes());

                    try {
                        $class = $subMapper->manipulateMapperClass($class, $typeMapperFactory);
                    } catch (CannotMapToPermissiveType) {
                        throw new CannotMapToPermissiveType($element->type()->toString(), (string)$key);
                    }

                    yield if_(
                        condition: call('array_key_exists', [value($key), variable('source')]),

                        // Key exists in source: map the value through the sub-mapper.
                        //
                        // if (array_key_exists(0, $source)) {
                        //     $result[0] = $this->mapSubType($source[0], $context->sub('0'));
                        // }
                        then: $subMapper->buildMappingNodes(
                            variable('source')->key(value($key)),
                            variable('context')->callMethod('sub', [value((string)$key)]),
                            variable('result')->key(value($key)),
                        ),

                        // If the key does not exist in source *and* the element is not optional.
                        else: when(
                            ! $element->isOptional(),
                            then: when(
                                condition: $this->settings->allowUndefinedValues,

                                // `allowUndefinedValues` is on: pass null so the sub-mapper can cast/coerce it
                                //
                                // $result[0] = $this->mapSubType(null, $context->sub('0'));
                                then: $subMapper->buildMappingNodes(
                                    value(null),
                                    variable('context')->callMethod('sub', [value((string)$key)]),
                                    variable('result')->key(value($key)),
                                ),

                                // `allowUndefinedValues` is off: the element is required but absent: report a
                                // missing-value error.
                                //
                                // $context->sub('0')->addMessage('missing value');
                                else: new AddMessageNode(
                                    variable('context')->callMethod('sub', [value((string)$key)]),
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

            // Handling unsealed/extra values
            // ==============================
            //
            // For unsealed lists, the remaining values are mapped through the
            // unsealed type, but only as long as they keep the list sequential.
            // For sealed lists, any remaining value is unexpected. The generator
            // is used to both yield nodes and mutate `$class` (registering the
            // unsealed value mapper method).
            ...(function () use ($typeMapperFactory, &$class, $dumpedType) {
                $definedKeys = array_keys($this->type->elements);

                if ($this->type->isUnsealed()) {
                    $unsealedType = $this->type->unsealedType();

                    // An unsealed list without a type (`list{string, ...}`)
                    // accepts anything for the extra values.
                    $subType = $unsealedType instanceof VacantType
                        ? MixedType::get()
                        : $unsealedType->subType();

                    $isPermissive = $subType instanceof MixedType || $subType instanceof UndefinedObjectType;
                    $permissiveTypeName = $subType instanceof UndefinedObjectType ? 'object' : 'mixed';

                    // $remaining = array_diff_key($source, [0 => 0, 1 => 1]);
                    yield variable('remaining')->assign(
                        call('array_diff_key', [
                            variable('source'),
                            value(array_flip($definedKeys)),
                        ]),
                    )->asStatement();

                    if ($isPermissive && ! $this->settings->allowPermissiveTypes) {
                        // Generate runtime code that throws when extra values are
                        // encountered, so the actual key appears in the exception path.
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

                        return;
                    }

                    $valueMapper = $typeMapperFactory->for($subType);
                    $class = $valueMapper->manipulateMapperClass($class, $typeMapperFactory);

                    // The next accepted key is the one right after the last
                    // declared element, and it grows with every accepted value.
                    //
                    // $expectedKey = 2;
                    yield variable('expectedKey')->assign(value(count($this->type->elements)))->asStatement();

                    // foreach ($remaining as $remainingKey => $remainingValue) {
                    //     if (! is_int($remainingKey) || $remainingKey !== $expectedKey) {
                    //         $context->sub('5')->addMessage('unexpected key');
                    //     } else {
                    //         $expectedKey++;
                    //         $result[$remainingKey] = $this->mapSubType($remainingValue, $context->sub('2'));
                    //     }
                    // }
                    yield forEach_(
                        variable('remaining'),
                        'remainingKey',
                        'remainingValue',
                        if_(
                            condition: logicalOr(
                                negate(call('is_int', [variable('remainingKey')])),
                                variable('remainingKey')->different(variable('expectedKey')),
                            ),
                            then: new AddMessageNode(
                                variable('context')->callMethod('sub', [call('strval', [variable('remainingKey')])]),
                                new UnexpectedKeyInSource(),
                                $this->type->toString(),
                                dumpValue(variable('remainingValue')),
                                $dumpedType,
                            ),
                            else: [
                                postIncrement(variable('expectedKey'))->asStatement(),
                                ...$valueMapper->buildMappingNodes(
                                    variable('remainingValue'),
                                    variable('context')->callMethod('sub', [call('strval', [variable('remainingKey')])]),
                                    variable('result')->key(variable('remainingKey')),
                                ),
                            ],
                        ),
                    );
                } elseif (! $this->settings->allowSuperfluousKeys) {
                    // Sealed list: any remaining value is unexpected.
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
            // A shaped list is a PHP array; `list` is not a native PHP type.
            returnType: '?array',
            body: $body,
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('map_shaped_list', $this->type->toString());
    }
}
