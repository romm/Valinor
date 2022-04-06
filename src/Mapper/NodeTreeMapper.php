<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper;

use CuyZ\Valinor\Mapper\Tree\NodeMapper;

final class NodeTreeMapper implements TreeMapper
{
    private NodeMapper $nodeMapper;

    public function __construct(NodeMapper $nodeMapper)
    {
        $this->nodeMapper = $nodeMapper;
    }

    public function map(string $signature, $source)
    {
        $node = $this->nodeMapper->map($signature, $source);

        if (! $node->isValid()) {
            throw new MappingError($node);
        }

        return $node->value();
    }
}
