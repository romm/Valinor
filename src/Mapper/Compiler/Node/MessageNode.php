<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\Node;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Tree\Message\BasicErrorMessage;
use CuyZ\Valinor\Mapper\Tree\Message\Message;

final class MessageNode extends Node
{
    public function __construct(
        private Message $message,
    ) {}

    public function compile(Compiler $compiler): Compiler
    {
        return $compiler->compile(
            Node::newClass(
                BasicErrorMessage::class,
                Node::value($this->message->body()),
                Node::value($this->message->code()),
                Node::array($this->message->parameters()),
            ),
        );
    }
}
