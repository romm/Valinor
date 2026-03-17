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
use CuyZ\Valinor\Mapper\Tree\Exception\SourceIsEmptyArray;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Type\Types\ArrayType;
use CuyZ\Valinor\Type\Types\IterableType;
use CuyZ\Valinor\Type\Types\NonEmptyArrayType;

use function CuyZ\Valinor\Compiler\{call, dumpValue, forEach_, if_, logicalAnd, negate, newClass, param, return_, this, throw_, value, variable, when};

/** @internal */
final class ArrayTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;

    public function __construct(
        private ArrayType|NonEmptyArrayType|IterableType $type,
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

                // Handling empty array
                // ====================
                //
                // Only when the target type is a non-empty-array.
                //
                // if ($source === []) {
                //     $context->addMessage('source is empty array');
                // }
                when(
                    condition: $this->type instanceof NonEmptyArrayType,
                    then: if_(
                        condition: variable('source')->equals(value([])),
                        then: [
                            new AddMessageNode(variable('context'), new SourceIsEmptyArray(), $this->type->toString(), value('[]')),
                            return_(value(null)),
                        ],
                    ),
                ),

                // Initializing the result
                // =======================
                //
                // $result = [];
                variable('result')->assign(value([]))->asStatement(),

                // Looping over the source
                // =======================
                //
                // foreach ($source as $key => $value) {
                //     if (! is_string($key) && ! is_int($key)) {
                //         throw new InvalidIterableKeyType($key, $context->path());
                //     }
                //
                //     $result[$key] = $this->mapSubType($value, $context->sub($key));
                // }
                forEach_(
                    value: variable('source'),
                    key: 'key',
                    item: 'value',
                    body: [
                        if_(
                            condition: logicalAnd(
                                negate(call('is_string', [variable('key')])),
                                negate(call('is_int', [variable('key')])),
                            ),
                            then: throw_(newClass(InvalidIterableKeyType::class, variable('key'), variable('context')->access('path')))->asStatement(),
                        ),
                        ...$subMapper->buildMappingNodes(
                            value: variable('value'),
                            context: variable('context')->callMethod('sub', [
                                call('strval', [variable('key')]),
                            ]),
                            target: variable('result')->key(variable('key')),
                        ),
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
                // return $result;
                return_(variable('result')),
            ],
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('map_array', $this->type->toString());
    }
}
