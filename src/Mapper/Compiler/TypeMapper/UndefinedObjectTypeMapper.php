<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotMapToPermissiveType;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidNodeValue;

use function CuyZ\Valinor\Compiler\{call, dumpValue, if_, value};

/** @internal */
final class UndefinedObjectTypeMapper implements TypeMapper
{
    public function __construct(
        private Settings $settings,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            if_(
                condition: call('is_object', [$value]),
                body: $target->assign($value)->asStatement(),
            )->else([
                new AddMessageNode($context, new InvalidNodeValue(), 'object', dumpValue($value)),
            ]),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        if (! $this->settings->allowPermissiveTypes) {
            throw new CannotMapToPermissiveType('object');
        }

        return $class;
    }
}
