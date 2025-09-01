<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree\Builder;

use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Tree\Exception\MissingNodeValue;
use CuyZ\Valinor\Mapper\Tree\Shell;
use CuyZ\Valinor\Type\Type;

/** @internal */
final class RootNodeBuilder
{
    /**
     * This property is used to detect circular references for objects.
     */
    public Type $currentRootType;

    public function __construct(
        private NodeBuilder $root,
        private Settings $settings,
    ) {}

    public function build(Shell $shell): Node
    {
        $shell = $shell->withSettings($this->settings);

        if (! $shell->hasValue()) {
            if (! $shell->allowUndefinedValues()) {
                return Node::error($shell, new MissingNodeValue($shell->type()));
            }

            $shell = $shell->withValue(null);
        }

        return $this->root->build($shell, $this);
    }

    public function withTypeAsCurrentRoot(Type $type): self
    {
        $self = clone $this;
        $self->currentRootType = $type;

        return $self;
    }

    public function typeWasSeen(Type $type): bool
    {
        return isset($this->currentRootType)
            && $type->toString() === $this->currentRootType->toString();
    }
}
