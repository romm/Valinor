<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Type\FloatType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Types\UnionType;
use CuyZ\Valinor\Utility\ValueDumper;

use function CuyZ\Valinor\Compiler\{call, castTo, className, if_, logicalOr, negate, param, return_, this, value, variable};

final class ScalarTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    private bool $allowScalarValueCasting;

    public function __construct(
        private ScalarType $type,
        private Settings $settings,
    ) {
        $this->allowScalarValueCasting = $settings->allowScalarValueCasting;
    }

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        if ($this->allowScalarValueCasting) {
            // Casting enabled: fall back to method call
            return [
                $target->assign(
                    this()->callMethod(
                        method: $this->methodName(),
                        arguments: [$value, $context],
                    ),
                )->asStatement(),
            ];
        }

        // Inline: type check directly, avoiding method call + eager sub-context allocation
        $acceptCondition = $this->type instanceof FloatType
            ? logicalOr($this->type->compiledAccept($value), call('is_int', [$value]))
            : $this->type->compiledAccept($value);

        $assignNode = $this->type instanceof FloatType
            ? $target->assign(castTo($this->type, $value))->asStatement()
            : $target->assign($value)->asStatement();

        return [
            if_(
                condition: $acceptCondition,
                body: $assignNode,
            )->else([
                $context->callMethod('addMessage', [
                    new MessageNode($this->type->errorMessage()),
                    value($this->type->toString()),
                    className(ValueDumper::class)->callStaticMethod('dump', [$value]),
                ])->asStatement(),
            ]),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        if (! $this->settings->allowScalarValueCasting) {
            return $class;
        }

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
