<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\SourceIsNotNull;
use CuyZ\Valinor\Utility\ValueDumper;

use function CuyZ\Valinor\Compiler\{className, if_, value};

/** @internal */
final class NullTypeMapper implements TypeMapper
{
    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            if_(
                condition: $value->different(value(null)),
                body: $context->callMethod('addMessage', [
                    new MessageNode(new SourceIsNotNull()),
                    value('null'),
                    className(ValueDumper::class)->callStaticMethod('dump', [$value]),
                ])->asStatement(),
            ),
            $target->assign(value(null))->asStatement(),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        return $class;
    }
}
