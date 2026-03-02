<?php

namespace CuyZ\Valinor\Mapper\Object;

use BackedEnum;
use CuyZ\Valinor\Compiler\Native\ComplianceNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Type\Types\EnumType;
use CuyZ\Valinor\Type\Types\Factory\ValueTypeFactory;
use CuyZ\Valinor\Type\Types\UnionType;

/** @internal */
class NativeEnumObjectBuilder implements ObjectBuilder
{
    private Arguments $arguments;

    private EnumType $enum;

    public function __construct(EnumType $type)
    {
        $types = [];

        foreach ($type->cases() as $case) {
            $value = $case instanceof BackedEnum ? $case->value : $case->name;

            $types[] = ValueTypeFactory::from($value);
        }

        $argumentType = UnionType::from(...$types);

        $this->enum = $type;
        $this->arguments = new Arguments(
            new Argument('value', $type->className() . '::$value', $argumentType)
        );
    }

    public function describeArguments(): Arguments
    {
        return $this->arguments;
    }

    public function buildObject(array $arguments): object
    {
        // @phpstan-ignore offsetAccess.invalidOffset (we know the `value` offset exists)
        return $this->enum->cases()[$arguments['value']];
    }

    /**
     * @return non-empty-list<Node>
     */
    public function compile(ComplianceNode $values): array
    {
        $enumName = $this->enum->className();
        $value = $values->key(Node::value('value'));

        if (is_subclass_of($enumName, BackedEnum::class)) {
            return [
                Node::return(Node::class($enumName)->callStaticMethod('from', [$value]))->asExpression(),
            ];
        }

        // Unit enums: resolve case by name using constant()
        return [
            Node::return(
                Node::functionCall('constant', [
                    Node::functionCall('sprintf', [
                        Node::value('%s::%s'),
                        Node::value($enumName),
                        $value,
                    ]),
                ]),
            )->asExpression(),
        ];
    }

    public function signature(): string
    {
        return $this->enum->readableSignature();
    }
}
