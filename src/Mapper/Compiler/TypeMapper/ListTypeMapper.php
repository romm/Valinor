<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidIterableKeyType;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceIsEmptyList;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Mapper\Tree\Message\BasicErrorMessage;
use CuyZ\Valinor\Type\Types\ListType;
use CuyZ\Valinor\Type\Types\NonEmptyListType;

use function CuyZ\Valinor\Compiler\{array_, call, dumpValue, forEach_, if_, logicalAnd, negate, newClass, param, postIncrement, return_, this, throw_, value, variable, when};

/** @internal */
final class ListTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;

    public function __construct(
        private ListType|NonEmptyListType $type,
        private Settings $settings,
        private ?string $variant = null,
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

        $subMapper = $typeMapperFactory->for($this->type->subType());
        $class = $subMapper->manipulateMapperClass($class, $typeMapperFactory);

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: '?' . $this->type->nativeType()->toString(),
            body: [
                // Handling null values
                // ====================
                when(
                    condition: $this->settings->allowUndefinedValues,

                    // When `allowUndefinedValues` is on
                    // =================================
                    //
                    // if ($source === null) {
                    //     $source = [];
                    // }
                    then: if_(
                        condition: variable('source')->equals(value(null)),
                        then: variable('source')->assign(value([]))->asStatement(),
                    ),

                    // When `allowUndefinedValues` is off
                    // ==================================
                    //
                    // if ($source === null) {
                    //     $context->addMessage('source must be iterable');
                    // }
                    else: if_(
                        condition: variable('source')->equals(value(null)),
                        then: [
                            new AddMessageNode(variable('context'), new SourceMustBeIterable(null), $this->type->toString(), value('*missing*')),
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
                        new AddMessageNode(variable('context'), new SourceMustBeIterable('value'), $this->type->toString(), dumpValue(variable('source'))),
                        return_(value(null)),
                    ],
                ),

                // Handling empty list
                // ===================
                //
                // Only when the target type is a non-empty-list.
                //
                // if ($source === []) {
                //     $context->addMessage('source is empty list');
                // }
                when(
                    condition: $this->type instanceof NonEmptyListType,
                    then: if_(
                        condition: variable('source')->equals(value([])),
                        then: [
                            new AddMessageNode(variable('context'), new SourceIsEmptyList(), $this->type->toString(), value('[]')),
                            return_(value(null)),
                        ],
                    ),
                ),

                // Initializing the result
                // =======================
                //
                // $result = [];
                variable('result')->assign(value([]))->asStatement(),

                // Initializing expected key counter
                // =================================
                //
                // $expectedKey = 0;
                when(
                    condition: ! $this->settings->allowNonSequentialList,
                    then: variable('expectedKey')->assign(value(0))->asStatement(),
                ),

                // Looping over the source
                // =======================
                //
                // foreach ($source as $key => $value) {
                //     …
                // }
                forEach_(
                    value: variable('source'),
                    key: 'key',
                    item: 'value',
                    body: [
                        when(
                            condition: $this->settings->allowNonSequentialList,

                            // When `allowNonSequentialList` is on
                            // ===================================
                            //
                            // $result[$expectedKey] = $this->mapSubType($value, $context->sub($expectedKey));
                            then: $subMapper->buildMappingNodes(
                                value: variable('value'),
                                context: variable('context')->callMethod('sub', [variable('expectedKey')]),
                                target: variable('result')->key(variable('expectedKey')),
                            ),

                            // When `allowNonSequentialList` is off
                            // ====================================
                            //
                            // if ($key === $expectedKey) {
                            //     $result[$expectedKey] = $this->mapSubType($value, $context->sub($expectedKey));
                            // } else {
                            //     if (! is_string($key) && ! is_int($key)) {
                            //         throw new InvalidIterableKeyType($key, $context->path());
                            //     }
                            //
                            //     $context->sub($key)->addMessage('invalid list key');
                            // }
                            else: [
                                if_(
                                    condition: variable('key')->equals(variable('expectedKey')),
                                    then: $subMapper->buildMappingNodes(
                                        value: variable('value'),
                                        context: variable('context')->callMethod('sub', [variable('expectedKey')]),
                                        target: variable('result')->key(variable('expectedKey')),
                                    ),
                                    else: [
                                        if_(
                                            condition: logicalAnd(
                                                negate(call('is_string', [variable('key')])),
                                                negate(call('is_int', [variable('key')])),
                                            ),
                                            then: throw_(newClass(InvalidIterableKeyType::class, variable('key'), variable('context')->access('path')))->asStatement(),
                                        ),
                                        new AddMessageNode(
                                            variable('context')->callMethod('sub', [call('strval', [variable('key')])]),
                                            newClass(
                                                BasicErrorMessage::class,
                                                value('Invalid sequential key {key}, expected {expected}.'),
                                                value('invalid_list_key'),
                                                array_([
                                                    'key' => dumpValue(variable('key')),
                                                    'expected' => call('strval', [variable('expectedKey')]),
                                                ]),
                                            ),
                                            $this->type->subType()->toString(),
                                            dumpValue(variable('value')),
                                        ),
                                    ]
                                ),
                            ]
                        ),

                        // Incrementing expected key counter
                        // =================================
                        //
                        // $expectedKey++;
                        postIncrement(variable('expectedKey'))->asStatement(),
                    ],
                ),

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
                // return array_values($result);
                return_(
                    call('array_values', [variable('result')]),
                ),
            ],
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('map_list', $this->type->toString(), $this->variantHashInput($this->type->toString()));
    }
}
