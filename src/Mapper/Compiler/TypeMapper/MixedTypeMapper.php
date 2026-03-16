<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotMapToPermissiveType;

/** @internal */
final class MixedTypeMapper implements TypeMapper
{
    public function __construct(
        private Settings $settings,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [$target->assign($value)->asStatement()];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        if (! $this->settings->allowPermissiveTypes) {
            throw new CannotMapToPermissiveType('mixed');
        }

        return $class;
    }
}
