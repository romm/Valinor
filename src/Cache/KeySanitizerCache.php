<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Cache;

use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Utility\Package;

use function hash;
use function microtime;
use function strstr;

/**
 * @internal
 *
 * @template EntryType
 * @implements Cache<EntryType>
 */
final class KeySanitizerCache implements Cache
{
    private static string $version;

    public function __construct(
        /** @var Cache<EntryType> */
        private Cache $delegate,
        private Settings $settings,
    ) {}

    public function get(string $key, mixed ...$arguments): mixed
    {
        //        $time = microtime(true);

        $todo = $this->delegate->get($this->sanitize($key), ...$arguments);

        //        echo "CACHE GET (KEY SANITIZER) — " . (microtime(true) - $time) * 1000 . 'ms' . PHP_EOL;

        return $todo;
    }

    public function set(string $key, CacheEntry $entry): void
    {
        $this->delegate->set($this->sanitize($key), $entry);
    }

    public function clear(): void
    {
        $this->delegate->clear();
    }

    /**
     * @return non-empty-string
     */
    private function sanitize(string $key): string
    {
        // @infection-ignore-all
        //        self::$version ??= PHP_VERSION . '/' . Package::version();
        self::$version ??= PHP_VERSION; // @todo

        $firstPart = strstr($key, "\0", before_needle: true);

        // @infection-ignore-all
        return $firstPart . hash('xxh128', $key . $this->settings->hash() . self::$version);
    }
}
