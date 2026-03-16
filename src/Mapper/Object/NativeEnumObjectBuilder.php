<?php

namespace CuyZ\Valinor\Mapper\Object;

use BackedEnum;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Type\Types\EnumType;
use CuyZ\Valinor\Type\Types\Factory\ValueTypeFactory;
use CuyZ\Valinor\Type\Types\UnionType;

use function CuyZ\Valinor\Compiler\{call, className, return_, value};

use function is_subclass_of;

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
    public function compile(Node $values): array
    {
        $enumName = $this->enum->className();
        $value = $values->key(value('value'));

        if (is_subclass_of($enumName, BackedEnum::class)) {
            return [
                return_(className($enumName)->callStaticMethod('from', [$value]))->asStatement(),
            ];
        }

        // Unit enums: resolve case by name using constant()
        return [
            return_(
                call('constant', [
                    call('sprintf', [
                        value('%s::%s'),
                        value($enumName),
                        $value,
                    ]),
                ]),
            )->asStatement(),
        ];
    }

    public function signature(): string
    {
        return $this->enum->readableSignature();
    }
}
