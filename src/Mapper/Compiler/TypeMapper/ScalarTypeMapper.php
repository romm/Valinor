<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Type\FloatType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Types\UnionType;
use CuyZ\Valinor\Utility\ValueDumper;

use function CuyZ\Valinor\Compiler\{call, castTo, className, if_, negate, param, return_, this, value, variable};

final class ScalarTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    public function __construct(
        private ScalarType $type,
    ) {}

    public function formatValueNode(Node $value, Node $context): Node
    {
        return this()->callMethod(
            method: $this->methodName(),
            arguments: [
                $value,
                $context,
            ],
        );
    }

    public function manipulateMapperClass(AnonymousClassNode $class, Settings $settings, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        $nodes = [];

        // Int-to-float auto-conversion (mirrors Shell::castFloatValue)
        if ($this->type instanceof FloatType) {
            $nodes[] = if_(
                condition: call('is_int', [variable('source')]),
                body: variable('source')->assign(
                    castTo($this->type, variable('source')),
                )->asStatement(),
            );
        }

        if (! $settings->allowScalarValueCasting) {
            $nodes = [
                ...$nodes,
                if_(
                    condition: negate($this->type->compiledAccept(variable('source'))->wrap()),
                    body: [
                        variable('context')->callMethod(
                            method: 'addMessage',
                            arguments: [
                                new MessageNode($this->type->errorMessage()),
                                value($this->type->toString()),
                                className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
                            ]
                        )->asStatement(),
                        return_(value(null)),
                    ],
                ),
                return_(variable('source')),
            ];
        } else {
            $nodes = [
                ...$nodes,
                if_(
                    condition: $this->type->compiledAccept(variable('source')),
                    body: return_(variable('source')),
                ),
                if_(
                    condition: $this->type->compiledCanCast(variable('source')),
                    body: return_(
                        $this->type->compiledCast(variable('source')),
                    ),
                ),
                variable('context')->callMethod('addMessage', [
                    new MessageNode($this->type->errorMessage()),
                    value($this->type->toString()),
                    className(ValueDumper::class)->callStaticMethod('dump', [variable('source')]),
                ])->asStatement(),
                return_(value(null)),
            ];
        }

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: $this->nullableReturnType(),
            body: $nodes,
        );
    }

    private function nullableReturnType(): string
    {
        $nativeType = $this->type->nativeType();

        if ($nativeType instanceof UnionType) {
            return $nativeType->toString() . '|null';
        }

        return '?' . $nativeType->toString();
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('map', $this->type->toString());
    }
}
