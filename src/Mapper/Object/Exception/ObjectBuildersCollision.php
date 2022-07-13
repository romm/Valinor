<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object\Exception;

use CuyZ\Valinor\Mapper\Object\ObjectBuilder;
use CuyZ\Valinor\Type\Types\ClassType;
use RuntimeException;

use function array_map;
use function implode;

/** @internal */
final class ObjectBuildersCollision extends RuntimeException
{
    public function __construct(ClassType $class, ObjectBuilder ...$builders)
    {
        $constructors = array_map(fn (ObjectBuilder $builder) => $builder->signature(), $builders);
        $constructors = implode('`, `', $constructors);

        parent::__construct(
            "A collision was detected between the following constructors of the class `{$class->toString()}`: `$constructors`.",
            1654955787
        );
    }
}
