<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object\Factory;

use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Mapper\Object\ReflectionObjectBuilder;
use CuyZ\Valinor\Type\Types\ClassType;

/** @internal */
final class ReflectionObjectBuilderFactory implements ObjectBuilderFactory
{
    private ClassDefinitionRepository $classDefinitionRepository;

    public function __construct(ClassDefinitionRepository $classDefinitionRepository)
    {
        $this->classDefinitionRepository = $classDefinitionRepository;
    }

    public function for(ClassType $type): iterable
    {
        $class = $this->classDefinitionRepository->for($type);

        return [new ReflectionObjectBuilder($class)];
    }
}
