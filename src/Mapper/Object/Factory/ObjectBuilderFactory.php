<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object\Factory;

use CuyZ\Valinor\Mapper\Object\ObjectBuilder;
use CuyZ\Valinor\Type\Types\ClassType;

/** @internal */
interface ObjectBuilderFactory
{
    /**
     * @return iterable<ObjectBuilder>
     */
    public function for(ClassType $type): iterable;
}
