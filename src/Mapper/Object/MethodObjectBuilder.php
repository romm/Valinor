<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\Parameters;
use CuyZ\Valinor\Mapper\Tree\Message\UserlandError;
use Exception;

use function CuyZ\Valinor\Compiler\{className, return_, throw_, try_, variable};

/** @internal */
final class MethodObjectBuilder implements ObjectBuilder
{
    private Arguments $arguments;

    public function __construct(
        private string $className,
        private string $methodName,
        private Parameters $parameters,
    ) {}

    public function describeArguments(): Arguments
    {
        return $this->arguments ??= Arguments::fromParameters($this->parameters);
    }

    public function buildObject(array $arguments): object
    {
        $methodName = $this->methodName;
        $arguments = new MethodArguments($this->parameters, $arguments);

        try {
            return ($this->className)::$methodName(...$arguments); // @phpstan-ignore-line
        } catch (Exception $exception) {
            throw UserlandError::from($exception);
        }
    }

    /**
     * @return non-empty-list<Node>
     */
    public function compile(Node $values): array
    {
        $variadicNodes = VariadicCompiler::compileVariadicArgs($this->parameters, $values);

        if ($variadicNodes !== null) {
            return [
                ...$variadicNodes,
                try_(
                    return_(className($this->className)->callStaticMethod($this->methodName, [variable('__flatArgs')->unpack()]))->asStatement(),
                )->catches(
                    exception: Exception::class,
                    body: throw_(className(UserlandError::class)->callStaticMethod('from', [variable('exception')])->asStatement()),
                ),
            ];
        }

        return [
            try_(
                return_(className($this->className)->callStaticMethod($this->methodName, [$values->unpack()]))->asStatement(),
            )->catches(
                exception: Exception::class,
                body: throw_(className(UserlandError::class)->callStaticMethod('from', [variable('exception')])->asStatement()),
            ),
        ];
    }

    public function signature(): string
    {
        return "$this->className::$this->methodName()";
    }
}
