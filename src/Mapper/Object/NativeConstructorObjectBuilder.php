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
    public function todo(ComplianceNode $values): array
    {
        return [
            Node::return(Node::newClass($this->class->name, $values->unpack()))->asExpression(),
        ];
    }

    public function signature(): string
    {
        return $this->class->methods->constructor()->signature;
    }
}
