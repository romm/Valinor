<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotMapToPermissiveType;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidNodeValue;
use CuyZ\Valinor\Utility\ValueDumper;

use function CuyZ\Valinor\Compiler\{call, className, if_, negate, param, return_, this, value, variable};

/** @internal */
final class UndefinedObjectTypeMapper implements TypeMapper
{
    public function __construct(
        private Settings $settings,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            $target->assign(
                this()->callMethod(
                    method: 'map_object',
                    arguments: [$value, $context],
                ),
            )->asStatement(),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        if (! $this->settings->allowPermissiveTypes) {
            throw new CannotMapToPermissiveType('object');
        }

        if ($class->hasMethod('map_object')) {
            return $class;
        }

        return $class->withMethod(
            name: 'map_object',
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: '?object',
            body: [
                if_(
                    condition: negate(call('is_object', [variable('source')])),
                    body: [
                        variable('context')->callMethod('addMessage', [
                            new MessageNode(new InvalidNodeValue()),
                            value('object'),
                            className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
                        ])->asStatement(),
                        return_(value(null)),
                    ],
                ),
                return_(variable('source')),
            ],
        );
    }
}
