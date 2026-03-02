<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree\Exception;

use RuntimeException;

use function implode;

/** @internal */
final class ObjectImplementationNotRegistered extends RuntimeException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(string $implementation, string $name, array $allowed)
    {
        $allowed = implode('`, `', $allowed);

        parent::__construct(
            "Invalid implementation `$implementation` for `$name`, it should be one of `$allowed`.",
        );
    }
}
