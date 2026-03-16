<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\Node;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Tree\Message\BasicErrorMessage;
use CuyZ\Valinor\Mapper\Tree\Message\HasCode;
use CuyZ\Valinor\Mapper\Tree\Message\HasParameters;
use CuyZ\Valinor\Mapper\Tree\Message\Message;

use function CuyZ\Valinor\Compiler\{array_, newClass, value};

final class MessageNode extends Node
{
    public function __construct(
        private Message $message,
    ) {}

    public function compile(Compiler $compiler): Compiler
    {
        $parameters = [];

        if ($this->message instanceof HasParameters) {
            foreach ($this->message->parameters() as $name => $parameter) {
                $parameters[$name] = value($parameter);
            }
        }

        return $compiler->compile(
            newClass(
                BasicErrorMessage::class,
                value($this->message->body()),
                value($this->message instanceof HasCode ? $this->message->code() : 'unknown'),
                array_($parameters),
            ),
        );
    }
}
