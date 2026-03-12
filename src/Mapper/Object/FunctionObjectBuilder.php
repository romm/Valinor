<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\FunctionObject;
use CuyZ\Valinor\Mapper\Tree\Message\UserlandError;
use CuyZ\Valinor\Type\ObjectType;
use Exception;

use function array_map;
use function array_shift;
use function array_values;
use function CuyZ\Valinor\Compiler\{className, return_, this, throw_, try_, value, variable};
use function hash;

/** @internal */
final class FunctionObjectBuilder implements ObjectBuilder
{
    private FunctionObject $function;

    private string $className;

    private Arguments $arguments;

    private bool $isDynamicConstructor;

    public function __construct(FunctionObject $function, ObjectType $type, private ?int $constructorIndex = null)
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
    public function compile(Node $values): array
    {
        if ($this->constructorIndex !== null) {
            $callNode = this()->access('customConstructors')->key(value($this->constructorIndex));
        } else {
            $callNode = this()->access('constructorCallbacks')->key(value($this->callbackKey()));
        }

        $arguments = [];

        if ($this->isDynamicConstructor) {
            $arguments[] = value($this->className);
        }

        $arguments[] = $values->unpack();

        return [
            try_(
                return_($callNode->call(arguments: $arguments))->asStatement(),
            )->catches(
                exception: Exception::class,
                body: throw_(className(UserlandError::class)->callStaticMethod('from', [variable('exception')]))->asStatement(),
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

    public function constructorIndex(): ?int
    {
        return $this->constructorIndex;
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
