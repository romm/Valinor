<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object;

use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\FunctionObject;
use CuyZ\Valinor\Mapper\Tree\Message\UserlandError;
use CuyZ\Valinor\Type\ObjectType;
use Exception;

use function array_map;
use function array_shift;
use function array_values;
use function hash;

/** @internal */
final class FunctionObjectBuilder implements ObjectBuilder
{
    private FunctionObject $function;

    private string $className;

    private Arguments $arguments;

    private bool $isDynamicConstructor;

    public function __construct(FunctionObject $function, ObjectType $type)
    {
        $definition = $function->definition;

        $arguments = array_map(
            Argument::fromParameter(...),
            array_values([...$definition->parameters])
        );

        $this->isDynamicConstructor = $definition->attributes->has(DynamicConstructor::class);

        if ($this->isDynamicConstructor) {
            array_shift($arguments);
        }

        $this->function = $function;
        $this->className = $type->className();
        $this->arguments = new Arguments(...$arguments);
    }

    public function describeArguments(): Arguments
    {
        return $this->arguments;
    }

    public function buildObject(array $arguments): object
    {
        $parameters = $this->function->definition->parameters;

        if ($this->isDynamicConstructor) {
            $arguments[$parameters->at(0)->name] = $this->className;
        }

        $arguments = new MethodArguments($parameters, $arguments);

        try {
            /** @var object */
            return ($this->function->callback)(...$arguments);
        } catch (Exception $exception) {
            throw UserlandError::from($exception);
        }
    }

    /**
     * @return non-empty-list<Node>
     */
    public function compile(ComplianceNode $values): array
    {
        $callbackKey = $this->callbackKey();
        $callNode = Node::this()->access('constructorCallbacks')->key(Node::value($callbackKey));

        $arguments = [];

        if ($this->isDynamicConstructor) {
            $arguments[] = Node::value($this->className);
        }

        $arguments[] = $values->unpack();

        return [
            Node::try(
                Node::return($callNode->call(arguments: $arguments))->asExpression(),
            )->catches(
                exception: Exception::class,
                body: Node::throw(Node::class(UserlandError::class)->callStaticMethod('from', [Node::variable('exception')]))->asExpression(),
            ),
        ];
    }

    /**
     * @return non-empty-string
     */
    public function callbackKey(): string
    {
        return hash('crc32', $this->function->definition->signature . '::' . $this->className);
    }

    /**
     * @return callable
     */
    public function callback(): mixed
    {
        return $this->function->callback;
    }

    public function isDynamic(): bool
    {
        return $this->isDynamicConstructor;
    }

    public function signature(): string
    {
        return $this->function->definition->signature;
    }
}
