<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree\Message;

final class BasicErrorMessage implements ErrorMessage, HasCode, HasParameters
{
    public function __construct(
        private string $body,
        private string $messageCode,
        /** @var array<string, string> */
        private array $parameters,
    ) {}

    public function body(): string
    {
        return $this->body;
    }

    public function code(): string
    {
        return $this->messageCode;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }
}
