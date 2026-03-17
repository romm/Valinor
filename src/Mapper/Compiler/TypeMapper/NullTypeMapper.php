<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceIsNotNull;

use function CuyZ\Valinor\Compiler\{dumpValue, if_, value};

/** @internal */
final class NullTypeMapper implements TypeMapper
{
    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            if_(
                condition: $value->different(value(null)),
                then: new AddMessageNode($context, new SourceIsNotNull(), 'null', dumpValue($value)),
            ),
            $target->assign(value(null))->asStatement(),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        return $class;
    }
}
