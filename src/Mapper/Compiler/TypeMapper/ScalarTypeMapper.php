<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Type\FloatType;
use CuyZ\Valinor\Type\ScalarType;

use function CuyZ\Valinor\Compiler\{call, castTo, dumpValue, if_, logicalOr};

/** @internal */
final class ScalarTypeMapper implements TypeMapper
{
    public function __construct(
        private ScalarType $type,
        private Settings $settings,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        if ($this->type instanceof FloatType) {
            // When the value is an integer and the type is a float, the value
            // is cast to float, to follow the rule of PHP regarding acceptance
            // of an integer value in a float type. Note that PHPStan/Psalm
            // analysis applies the same rule.
            //
            // if (scalarTypeAccepValue($value) && is_int($value)) {
            //     $target = (float)$value;
            // }
            $condition = logicalOr($this->type->compiledAccept($value), call('is_int', [$value]));
            $then = $target->assign(castTo($this->type, $value))->asStatement();
        } else {
            // if (scalarTypeAccepValue($value)) {
            //     $target = $value;
            // }
            $condition = $this->type->compiledAccept($value);
            $then = $target->assign($value)->asStatement();
        }

        if ($this->settings->allowScalarValueCasting) {
            // if (scalarTypeAccepValue($value)) {
            //     …
            // } elseif (scalarTypeCanCast($value)) {
            //     $target = scalarTypeCast($value);
            // } else {
            //     $context->addMessage('scalar message: invalid value');
            // }
            $else = if_(
                condition: $this->type->compiledCanCast($value),
                then: $target->assign($this->type->compiledCast($value))->asStatement(),
                else: new AddMessageNode($context, $this->type->errorMessage(), $this->type->toString(), dumpValue($value)),
            );
        } else {
            // if (scalarTypeAccepValue($value)) {
            //     …
            // } else {
            //     $context->addMessage('scalar message: invalid value');
            // }
            $else = new AddMessageNode($context, $this->type->errorMessage(), $this->type->toString(), dumpValue($value));
        }

        return [
            if_(condition: $condition, then: $then, else: $else),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        return $class;
    }
}
