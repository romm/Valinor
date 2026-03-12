<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\ClassDefinition;

use function count;
use function CuyZ\Valinor\Compiler\{closure, newClass, return_, value, variable};

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
    public function compile(Node $values): array
    {
        $nodes = [
            variable('object')->assign(newClass($this->class->name))->asStatement(),
        ];

        // @todo we should check if properties are not readonly, in which case we don't need ->call()
        // Always use the closure approach with ->call() to support readonly
        // properties, which require being set from within the class scope.
        $nodes[] = closure(
            body: [...(function () use ($values) {
                foreach ($this->class->properties as $property) {
                    yield variable('this')->access($property->name)->assign($values->key(value($property->name)))->asStatement();
                }
            })()],
            uses: ['values'],
        )->wrap()->callMethod('call', [variable('object')])->asStatement();

        return [
            ...$nodes,
            return_(variable('object'))->asStatement(),
        ];
    }

    public function signature(): string
    {
        return $this->class->name . ' (properties)';
    }
}
