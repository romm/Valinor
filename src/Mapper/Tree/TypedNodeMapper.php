<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree;

use CuyZ\Valinor\Mapper\Exception\InvalidMappingTypeSignature;
use CuyZ\Valinor\Mapper\Tree\Builder\RootNodeBuilder;
use CuyZ\Valinor\Type\Parser\Exception\InvalidType;
use CuyZ\Valinor\Type\Parser\TypeParser;

/** @internal */
final class TypedNodeMapper implements NodeMapper
{
    private TypeParser $typeParser;

    private RootNodeBuilder $nodeBuilder;

    public function __construct(TypeParser $typeParser, RootNodeBuilder $nodeBuilder)
    {
        $this->typeParser = $typeParser;
        $this->nodeBuilder = $nodeBuilder;
    }

    /**
     * @param mixed $source
     */
    public function map(string $signature, $source): Node
    {
        try {
            $type = $this->typeParser->parse($signature);
        } catch (InvalidType $exception) {
            throw new InvalidMappingTypeSignature($signature, $exception);
        }

        $shell = Shell::root($type, $source);

        return $this->nodeBuilder->build($shell);
    }
}
