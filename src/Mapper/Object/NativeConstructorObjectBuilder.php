<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object;

use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\ClassDefinition;
use CuyZ\Valinor\Mapper\Tree\Message\UserlandError;
use Exception;

use function CuyZ\Valinor\Compiler\{className, newClass, return_, throw_, try_, variable};

/** @internal */
final class NativeConstructorObjectBuilder implements ObjectBuilder
{
    private Arguments $arguments;

    public function __construct(private ClassDefinition $class) {}

    public function describeArguments(): Arguments
    {
        return $this->arguments ??= Arguments::fromParameters($this->class->methods->constructor()->parameters);
    }

    public function buildObject(array $arguments): object
    {
        $className = $this->class->name;
        $arguments = new MethodArguments($this->class->methods->constructor()->parameters, $arguments);

        try {
            return new $className(...$arguments);
        } catch (Exception $exception) {
            throw UserlandError::from($exception);
        }
    }

    /**
     * @return non-empty-list<Node>
     */
    public function compile(Node $values): array
    {
        $variadicNodes = VariadicCompiler::compileVariadicArgs($this->class->methods->constructor()->parameters, $values);

        if ($variadicNodes !== null) {
            return [
                ...$variadicNodes,
                try_(
                    return_(newClass($this->class->name, variable('__flatArgs')->unpack()))->asStatement(),
                )->catches(
                    exception: Exception::class,
                    body: throw_(className(UserlandError::class)->callStaticMethod('from', [variable('exception')]))->asStatement(),
                ),
            ];
        }

        return [
            try_(
                return_(newClass($this->class->name, $values->unpack()))->asStatement(),
            )->catches(
                exception: Exception::class,
                body: throw_(className(UserlandError::class)->callStaticMethod('from', [variable('exception')]))->asStatement(),
            ),
        ];
    }

    public function signature(): string
    {
        return $this->class->methods->constructor()->signature;
    }
}
