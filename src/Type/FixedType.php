<?php

namespace CuyZ\Valinor\Type;

// @todo rename children to Fixed*Type?
/** @internal */
interface FixedType extends Type
{
    /**
     * @return scalar
     */
    public function value();
}
