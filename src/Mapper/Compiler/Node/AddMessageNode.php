<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\Node;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Tree\Message\Message;

use function CuyZ\Valinor\Compiler\value;

/** @internal */
final class AddMessageNode extends Node
{
    public function __construct(
        private Node $context,
        private Message|Node $message,
        private string $type,
        private Node $sourceValue,
        private ?string $expectedSignature = null,
    ) {
        $this->message = $message instanceof Message
            ? new MessageNode($message)
            : $message;
    }

    public function compile(Compiler $compiler): Compiler
    {
        $args = [$this->message, value($this->type), $this->sourceValue];

        if ($this->expectedSignature !== null) {
            $args[] = value($this->expectedSignature);
        }

        return $compiler->compile(
            $this->context->callMethod('addMessage', $args)->asStatement()
        );
    }
}
