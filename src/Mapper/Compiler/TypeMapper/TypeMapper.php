<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;

/** @internal */
interface TypeMapper
{
    /**
     * @return list<Node>
     */
    public function buildMappingNodes(Node $value, Node $context, Node $target): array;

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode;
}
