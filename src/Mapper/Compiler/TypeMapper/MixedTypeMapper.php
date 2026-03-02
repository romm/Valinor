<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotMapToPermissiveType;

/** @internal */
final class MixedTypeMapper implements TypeMapper
{
    public function formatValueNode(ComplianceNode $value, ComplianceNode $context): Node
    {
        // Mixed type: return the value as-is
        return $value;
    }

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        if (! $settings->allowPermissiveTypes) {
            throw new CannotMapToPermissiveType('mixed');
        }

        return $class;
    }
}
