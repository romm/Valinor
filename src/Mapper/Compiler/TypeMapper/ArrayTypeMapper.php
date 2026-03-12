<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;

use function CuyZ\Valinor\Compiler\{call, forEach_, if_, negate, newClass, param, return_, this, throw_, value, variable};
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidIterableKeyType;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceIsEmptyArray;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceMustBeIterable;
use CuyZ\Valinor\Type\Types\ArrayType;
use CuyZ\Valinor\Type\Types\IterableType;
use CuyZ\Valinor\Type\Types\NonEmptyArrayType;
use CuyZ\Valinor\Utility\ValueDumper;

/** @internal */
final class ArrayTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    public function __construct(
        private ArrayType|NonEmptyArrayType|IterableType $type,
    ) {}

    public function formatValueNode(Node $value, Node $context): Node
    {
        return this()->callMethod(
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
        $class = $class->withMethod($methodName);

        $subMapper = $typeMapperFactory->for($this->type->subType());
        $class = $subMapper->manipulateMapperClass($class, $settings, $typeMapperFactory);

        $nodes = IterableValidationNodes::build($settings, $this->type);

        if ($this->type instanceof NonEmptyArrayType) {
            $nodes[] = if_(
                condition: variable('source')->equals(value([])),
                body: [
                    variable('context')->callMethod('addMessage', [
                        new MessageNode(new SourceIsEmptyArray()),
                        value($this->type->toString()),
                        value('[]'),
                    ])->asStatement(),
                    return_(value(null)),
                ],
            );
        }

        // Initialize result array
        $nodes[] = variable('result')->assign(value([]))->asStatement();

        // forEach loop: validate keys and map sub-values
        $forEachBody = [
            if_(
                condition: negate(call('is_string', [variable('key')]))
                    ->and(negate(call('is_int', [variable('key')]))),
                body: throw_(newClass(InvalidIterableKeyType::class, variable('key'), variable('context')->access('path')))->asStatement(),
            ),
            // Map sub-value
            variable('result')->key(variable('key'))->assign(
                $subMapper->formatValueNode(
                    variable('value'),
                    variable('context')->callMethod('sub', [
                        call('strval', [variable('key')]),
                    ]),
                ),
            )->asStatement(),
        ];

        $nodes[] = forEach_(
            value: variable('source'),
            key: 'key',
            item: 'value',
            body: $forEachBody,
        );

        // Check for errors
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
            returnType: '?' . $this->type->nativeType()->toString(),
            body: $nodes,
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
