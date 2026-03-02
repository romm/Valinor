<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

/**
 * A simple registry for storing and retrieving runtime callbacks.
 * Used to pass closures and other non-serializable callables to compiled mappers.
 *
 * @internal
 */
final class ConstructorCallbackRegistry
{
    /** @var array<string, mixed> */
    private array $callbacks = [];

    /**
     * Register a callback with a unique key.
     */
    public function register(string $key, mixed $callback): void
    {
        $this->callbacks[$key] = $callback;
    }

    /**
     * Retrieve a callback by its key.
     */
    public function get(string $key): mixed
    {
        return $this->callbacks[$key];
    }

    /**
     * Get all registered callbacks.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->callbacks;
    }

    /**
     * Clear all registered callbacks.
     */
    public function reset(): void
    {
        $this->callbacks = [];
    }
}
