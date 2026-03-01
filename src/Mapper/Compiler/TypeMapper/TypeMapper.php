<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\TodoMapper;

interface TypeMapper
{
    public function formatValueNode(ComplianceNode $value, ComplianceNode $context): Node;

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TodoMapper $todoMapper): AnonymousClassNode;
}
