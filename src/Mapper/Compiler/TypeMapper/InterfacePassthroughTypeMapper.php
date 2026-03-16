<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;

use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveObjectType;
use CuyZ\Valinor\Type\ObjectType;

use function CuyZ\Valinor\Compiler\{if_, newClass, param, return_, this, throw_, value, variable};

/** @internal */
final class InterfacePassthroughTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    public function __construct(
        private ObjectType $type,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            $target->assign(
                this()->callMethod(
                    method: $this->methodName(),
                    arguments: [
                        $value,
                        $context,
                    ],
                ),
            )->asStatement(),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: 'mixed',
            body: [
                if_(
                    condition: variable('source')->instanceOf($this->type->className()),
                    body: return_(variable('source')),
                ),
                throw_(
                    newClass(
                        CannotResolveObjectType::class,
                        value($this->type->className()),
                    ),
                )->asStatement(),
            ],
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('map_passthrough', $this->type->toString());
    }
}
