<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object;

use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\ClassDefinition;
use CuyZ\Valinor\Mapper\Tree\Message\UserlandError;
use Exception;

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
    public function compile(ComplianceNode $values): array
    {
        // Check for variadic parameters
        $variadicName = null;

        foreach ($this->class->methods->constructor()->parameters as $parameter) {
            if ($parameter->isVariadic) {
                $variadicName = $parameter->name;

                break;
            }
        }

        if ($variadicName !== null) {
            // Flatten variadic: build positional args list, then spread
            $nonVariadicNames = [];

            foreach ($this->class->methods->constructor()->parameters as $parameter) {
                if (! $parameter->isVariadic) {
                    $nonVariadicNames[] = $parameter->name;
                }
            }

            $flatParts = [];

            foreach ($nonVariadicNames as $name) {
                $flatParts[] = Node::array([$values->key(Node::value($name))]);
            }

            $flatParts[] = Node::functionCall('array_values', [$values->key(Node::value($variadicName))]);

            return [
                Node::variable('__flatArgs')->assign(
                    count($flatParts) === 1 ? $flatParts[0] : Node::functionCall('array_merge', $flatParts),
                )->asExpression(),
                Node::try(
                    Node::return(Node::newClass($this->class->name, Node::variable('__flatArgs')->unpack()))->asExpression(),
                )->catches(
                    exception: Exception::class,
                    body: Node::throw(Node::class(UserlandError::class)->callStaticMethod('from', [Node::variable('exception')]))->asExpression(),
                ),
            ];
        }

        return [
            Node::try(
                Node::return(Node::newClass($this->class->name, $values->unpack()))->asExpression(),
            )->catches(
                exception: Exception::class,
                body: Node::throw(Node::class(UserlandError::class)->callStaticMethod('from', [Node::variable('exception')]))->asExpression(),
            ),
        ];
    }

    public function signature(): string
    {
        return $this->class->methods->constructor()->signature;
    }
}
