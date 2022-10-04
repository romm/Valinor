<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree\Visitor;

use CuyZ\Valinor\Mapper\Tree\Shell;

// @todo delete or mark as deprecated?
/** @internal */
interface ShellVisitor
{
    public function visit(Shell $shell): Shell;
}
