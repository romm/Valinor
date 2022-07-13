<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Attribute;

use Attribute;
use CuyZ\Valinor\Definition\ClassDefinition;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Object\MethodObjectBuilder;
use CuyZ\Valinor\Type\Types\ClassType;

/**
 * @api
 *
 * @deprecated This attribute should not be used anymore, the method
 *             `MapperBuilder::registerConstructor()` should be used instead.
 *
 * @Annotation
 * @NamedArgumentConstructor
 * @Target({"CLASS"})
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class StaticMethodConstructor implements ObjectBuilderFactory
{
    private string $methodName;

    private ClassDefinition $classDefinition;

    public function __construct(string $methodName)
    {
        $this->methodName = $methodName;
    }

    /**
     * Don't do this at home!
     */
    public function setClassDefinition(ClassDefinition $classDefinition): void
    {
        $this->classDefinition = $classDefinition;
    }

    public function for(ClassType $type): iterable
    {
        return [new MethodObjectBuilder($this->classDefinition, $this->methodName)];
    }
}
