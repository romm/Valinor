<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\MessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Type\FloatType;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Utility\ValueDumper;

use function CuyZ\Valinor\Compiler\{call, castTo, className, if_, logicalOr, value};

final class ScalarTypeMapper implements TypeMapper
{
    public function __construct(
        private ScalarType $type,
        private Settings $settings,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        $acceptCondition = $this->type instanceof FloatType
            ? logicalOr($this->type->compiledAccept($value), call('is_int', [$value]))
            : $this->type->compiledAccept($value);

        $assignNode = $this->type instanceof FloatType
            ? $target->assign(castTo($this->type, $value))->asStatement()
            : $target->assign($value)->asStatement();

        $errorNodes = [
            $context->callMethod('addMessage', [
                new MessageNode($this->type->errorMessage()),
                value($this->type->toString()),
                className(ValueDumper::class)->callStaticMethod('dump', [$value]),
            ])->asStatement(),
        ];

        if (! $this->settings->allowScalarValueCasting) {
            return [
                if_(
                    condition: $acceptCondition,
                    body: $assignNode,
                )->else($errorNodes),
            ];
        }

        return [
            if_(
                condition: $acceptCondition,
                body: $assignNode,
            )->else([
                if_(
                    condition: $this->type->compiledCanCast($value),
                    body: $target->assign($this->type->compiledCast($value))->asStatement(),
                )->else($errorNodes),
            ]),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        return $class;
    }
}
