<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object\Factory;

use CuyZ\Valinor\Mapper\Object\Exception\PermissiveTypeNotAllowed;
use CuyZ\Valinor\Type\Types\ClassType;
use CuyZ\Valinor\Utility\PermissiveTypeFound;
use CuyZ\Valinor\Utility\TypeHelper;

/** @internal */
final class StrictTypesObjectBuilderFactory implements ObjectBuilderFactory
{
    private ObjectBuilderFactory $delegate;

    public function __construct(ObjectBuilderFactory $delegate)
    {
        $this->delegate = $delegate;
    }

    public function for(ClassType $type): iterable
    {
        $builders = $this->delegate->for($type);

        foreach ($builders as $builder) {
            $arguments = $builder->describeArguments();

            foreach ($arguments as $argument) {
                try {
                    TypeHelper::checkPermissiveType($argument->type());
                } catch (PermissiveTypeFound $exception) {
                    throw new PermissiveTypeNotAllowed($builder, $argument, $exception);
                }
            }
        }

        return $builders;
    }
}
