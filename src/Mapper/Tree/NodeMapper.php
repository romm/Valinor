<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree;

interface NodeMapper
{
    /**
     * @param mixed $source
     */
    public function map(string $signature, $source): Node;
}
