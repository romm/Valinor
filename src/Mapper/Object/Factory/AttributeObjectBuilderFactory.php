<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object\Factory;

use CuyZ\Valinor\Attribute\StaticMethodConstructor;
use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Mapper\Object\Exception\TooManyObjectBuilderFactoryAttributes;
use CuyZ\Valinor\Type\Types\ClassType;

use function count;

/** @internal */
final class AttributeObjectBuilderFactory implements ObjectBuilderFactory
{
    private ObjectBuilderFactory $delegate;

    private ClassDefinitionRepository $classDefinitionRepository;

    public function __construct(ObjectBuilderFactory $delegate, ClassDefinitionRepository $classDefinitionRepository)
    {
        $this->delegate = $delegate;
        $this->classDefinitionRepository = $classDefinitionRepository;
    }

    public function for(ClassType $type): iterable
    {
        $class = $this->classDefinitionRepository->for($type);

        $attributes = $class->attributes()->ofType(ObjectBuilderFactory::class);

        if (count($attributes) === 0) {
            return $this->delegate->for($type);
        }

        if (count($attributes) > 1) {
            throw new TooManyObjectBuilderFactoryAttributes($class, $attributes);
        }

        $attribute = $attributes[0];

        if ($attribute instanceof StaticMethodConstructor) {
            $attribute->setClassDefinition($class);
        }

        return $attribute->for($type);
    }
}
