<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Tests\Fake\Mapper\Object\Factory;

use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Tests\Fake\Mapper\Object\FakeObjectBuilder;
use CuyZ\Valinor\Type\Types\ClassType;

final class FakeObjectBuilderFactory implements ObjectBuilderFactory
{
    public function for(ClassType $type): iterable
    {
        return [new FakeObjectBuilder()];
    }
}
