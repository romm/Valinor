<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ObjectTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ScalarTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\TypeMapper;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Type;
use RuntimeException;

final class TodoMapper
{
    public function __construct(
        private ClassDefinitionRepository $classDefinitionRepository,
        private ObjectBuilderFactory $objectBuilderFactory,
    ) {}

    public function for(Type $type): TypeMapper
    {
        return match (true) {
            $type instanceof ObjectType => new ObjectTypeMapper(
                $class = $this->classDefinitionRepository->for($type),
                $this->objectBuilderFactory->for($class),
            ),
            $type instanceof ScalarType => new ScalarTypeMapper($type),
            default => throw new RuntimeException('@todo'), // @todo
        };
    }
}
