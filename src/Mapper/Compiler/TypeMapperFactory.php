<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ArrayTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ListTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\NullTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ObjectTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ScalarTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\ShapedArrayTypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\TypeMapper;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\UnionTypeMapper;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\ArrayType;
use CuyZ\Valinor\Type\Types\IterableType;
use CuyZ\Valinor\Type\Types\ListType;
use CuyZ\Valinor\Type\Types\NonEmptyArrayType;
use CuyZ\Valinor\Type\Types\NonEmptyListType;
use CuyZ\Valinor\Type\Types\NullType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use CuyZ\Valinor\Type\Types\UnionType;
use RuntimeException;

final class TypeMapperFactory
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
            $type instanceof NullType => new NullTypeMapper(),
            $type instanceof UnionType => new UnionTypeMapper($type),
            $type instanceof ShapedArrayType => new ShapedArrayTypeMapper($type),
            $type instanceof ListType,
            $type instanceof NonEmptyListType => new ListTypeMapper($type),
            $type instanceof ArrayType,
            $type instanceof NonEmptyArrayType,
            $type instanceof IterableType => new ArrayTypeMapper($type),
            default => throw new RuntimeException('Unsupported type for compiled mapper: ' . $type->toString()),
        };
    }
}
