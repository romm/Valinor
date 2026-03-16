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
use CuyZ\Valinor\Type\Types\ListType;
use CuyZ\Valinor\Type\Types\NonEmptyListType;

use function CuyZ\Valinor\Compiler\{call, forEach_, if_, negate, newClass, param, return_, this, throw_, value, variable};
/** @internal */
final class ListTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    public function __construct(
        private ListType|NonEmptyListType $type,
        private Settings $settings,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            $target->assign(
                this()->callMethod(
                    method: $this->methodName(),
                    arguments: [
                        $value,
                        $context,
                    ],
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

        $nodes = IterableValidationNodes::build($this->settings, $this->type);

        // Check non-empty for NonEmptyListType
        if ($this->type instanceof NonEmptyListType) {
            $nodes[] = if_(
                condition: variable('source')->equals(value([])),
                body: [
                    new AddMessageNode(variable('context'), new SourceIsEmptyList(), $this->type->toString(), value('[]')),
                    return_(value(null)),
                ],
            );
        }

        // Initialize result array
        $nodes[] = variable('result')->assign(value([]))->asStatement();

        // forEach loop over source: validate keys and map values
        $forEachBody = [];

        // Validate key type
        $forEachBody[] = if_(
            condition: negate(call('is_string', [variable('key')]))
                ->and(negate(call('is_int', [variable('key')]))),
            body: throw_(newClass(InvalidIterableKeyType::class, variable('key'), variable('context')->access('path')))->asStatement(),
        );

        // Map value through sub-type mapper
        $forEachBody = [
            ...$forEachBody,
            ...$subMapper->buildMappingNodes(
                variable('value'),
                variable('context')->callMethod('sub', [
                    call('strval', [variable('key')]),
                ]),
                variable('result')->key(variable('key')),
            ),
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

        // Return array_values to ensure sequential list keys
        $nodes[] = return_(
            call('array_values', [variable('result')]),
        );

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
        return self::buildMethodName('map_list', $this->type->toString());
    }
}
