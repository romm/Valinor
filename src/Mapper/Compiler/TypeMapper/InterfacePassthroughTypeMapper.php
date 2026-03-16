<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveObjectType;
use CuyZ\Valinor\Type\ObjectType;

use function CuyZ\Valinor\Compiler\{if_, newClass, throw_, value};

/** @internal */
final class InterfacePassthroughTypeMapper implements TypeMapper
{
    public function __construct(
        private ObjectType $type,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            if_(
                condition: $value->instanceOf($this->type->className()),
                body: $target->assign($value)->asStatement(),
            )->else([
                throw_(
                    newClass(
                        CannotResolveObjectType::class,
                        value($this->type->className()),
                    ),
                )->asStatement(),
            ]),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        return $class;
    }
}
