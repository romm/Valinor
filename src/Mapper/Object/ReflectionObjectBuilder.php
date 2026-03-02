<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object;

use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\ClassDefinition;

use function count;

/** @internal */
final class ReflectionObjectBuilder implements ObjectBuilder
{
    private Arguments $arguments;

    public function __construct(private ClassDefinition $class) {}

    public function describeArguments(): Arguments
    {
        return $this->arguments ??= Arguments::fromProperties($this->class->properties);
    }

    public function buildObject(array $arguments): object
    {
        $object = new ($this->class->name)();

        if (count($arguments) > 0) {
            (function () use ($arguments): void {
                foreach ($arguments as $name => $value) {
                    $this->{$name} = $value; // @phpstan-ignore-line
                }
            })->call($object);
        }

        return $object;
    }

    /**
     * @return non-empty-list<Node>
     */
    public function compile(ComplianceNode $values): array
    {
        $nodes = [
            Node::variable('object')->assign(Node::newClass($this->class->name))->asExpression(),
        ];

        // @todo we should check if properties are not readonly, in which case we don't need ->call()
        // Always use the closure approach with ->call() to support readonly
        // properties, which require being set from within the class scope.
        $nodes[] = Node::closure(
            ...(function () use ($values) {
                foreach ($this->class->properties as $property) {
                    yield Node::variable('this')->access($property->name)->assign($values->key(Node::value($property->name)))->asExpression();
                }
            })(),
        )->uses('values')->wrap()->callMethod('call', [Node::variable('object')])->asExpression();

        return [
            ...$nodes,
            Node::return(Node::variable('object'))->asExpression(),
        ];
    }

    public function signature(): string
    {
        return $this->class->name . ' (properties)';
    }
}
