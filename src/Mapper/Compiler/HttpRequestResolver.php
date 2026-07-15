<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Mapper\Http\HttpRequest;
use CuyZ\Valinor\Mapper\Tree\Exception\HttpRequestKeyCollision;
use CuyZ\Valinor\Type\Types\UnresolvableType;

use function array_intersect_key;
use function array_key_exists;

/**
 * Spreads the values of an HTTP request over the elements of a shaped array,
 * the same way `HttpRequestNodeBuilder` does for the runtime mapper.
 *
 * Which source an element must be read from is known at compile time, as it
 * comes from the attributes of the element, and is given by `$elementSources`.
 * The values themselves are only known at runtime, hence this helper.
 *
 * @internal
 */
final class HttpRequestResolver
{
    public const FROM_ROUTE = 'route';
    public const FROM_QUERY = 'query';
    public const FROM_BODY = 'body';
    public const FROM_ANY = 'any';

    /**
     * Returns the values the shaped array must be mapped with, or `null` when
     * an error was reported.
     *
     * @param array<string, self::FROM_*> $elementSources
     * @return array<mixed>|null
     */
    public function resolve(
        HttpRequest $request,
        MappingContext $context,
        array $elementSources,
        bool $queryAsRoot,
        bool $bodyAsRoot,
    ): ?array {
        $route = $request->routeParameters;

        // When an element takes the whole query or body, these values belong to
        // it alone and must not be spread over the other elements.
        $query = $queryAsRoot ? [] : $request->queryParameters;
        $body = $bodyAsRoot ? [] : $request->bodyValues;

        foreach ($elementSources as $key => $source) {
            // An element bound to one source must *NEVER* be filled from
            // another one, even when the value is missing from its own source.
            if ($source === self::FROM_ROUTE) {
                unset($query[$key], $body[$key]);
            } elseif ($source === self::FROM_QUERY) {
                unset($route[$key], $body[$key]);
            } elseif ($source === self::FROM_BODY) {
                unset($route[$key], $query[$key]);
            }
        }

        // An element that is not bound to a source is filled from whichever
        // source holds it, so the same key coming from two sources is ambiguous.
        $collisions = array_intersect_key($route, $query)
            + array_intersect_key($route, $body)
            + array_intersect_key($query, $body);

        $hasCollision = false;

        foreach ($elementSources as $key => $source) {
            if ($source !== self::FROM_ANY || ! array_key_exists($key, $collisions)) {
                continue;
            }

            $hasCollision = true;

            $context->sub($key)->addMessage(
                new HttpRequestKeyCollision($key),
                UnresolvableType::forInvalidKey()->toString(),
                '*none*',
            );
        }

        if ($hasCollision) {
            return null;
        }

        return $route + $query + $body;
    }
}
