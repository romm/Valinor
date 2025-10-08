<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Type\Parser;

use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Utility\TypeHelper;

/** @internal */
final class VacantTypeAssignerParser implements TypeParser
{
    public function __construct(
        private TypeParser $delegate,
        /** @var array<non-empty-string, Type> */
        private array $vacantTypes,
    ) {
        // @todo big boost here
        //        foreach ($this->vacantTypes as $key => $vacantType) {
        //            $this->vacantTypes[$key] = TypeHelper::assignVacantTypes($vacantType, $this->vacantTypes);
        //        }
    }

    public function parse(string $raw): Type
    {
        $type = $this->delegate->parse($raw);

        if ($this->vacantTypes !== []) {
            $type = TypeHelper::assignVacantTypes($type, $this->vacantTypes);
        }

        return $type;
    }
}
