<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Http;

use CuyZ\Valinor\Mapper\Exception\PsrRequestParsedBodyIsObject;
use Psr\Http\Message\ServerRequestInterface;

use function is_object;

/**
 * This class represents an HTTP request that can be given to the mapper to have
 * custom mapping rules applied, based on the request's data.
 *
 * An HTTP request can be built directly from a PSR-7 request:
 *
 * ```
 * $request = \CuyZ\Valinor\Mapper\Http\HttpRequest::fromPsr(
 *     $psrRequest, // PSR-7 `ServerRequestInterface` instance
 *     $routeParameters, // Results from a router
 * );
 * ```
 *
 * The following rules apply:
 *
 * - Route parameters must be marked with `#[FromRoute]` attribute.
 * - Query parameters must be marked with `#[FromQuery]` attribute.
 * - Body values must be marked with `#[FromBody]` attribute.
 *
 * Example of a GET request
 * ========================
 *
 * ```
 * use CuyZ\Valinor\Mapper\Http\FromQuery;
 * use CuyZ\Valinor\Mapper\Http\FromRoute;
 * use CuyZ\Valinor\Mapper\Http\HttpRequest;
 * use CuyZ\Valinor\MapperBuilder;
 *
 * final class ListArticles
 * {
 *     // GET /api/authors/{authorId}/articles
 *     public function __invoke(
 *         // Comes from the route
 *         #[FromRoute] string $authorId,
 *
 *         // All come from query parameters
 *         #[FromQuery] string $status,
 *         #[FromQuery] string $sort,
 *         #[FromQuery] int $page,
 *         #[FromQuery] int $limit,
 *     ): ResponseInterface { … }
 * }
 *
 * // GET /api/authors/42/articles?status=published&sort=date-desc&page=2&limit=10
 * $request = new HttpRequest(
 *     routeParameters: ['authorId' => 42],
 *     queryParameters: [
 *         'status' => 'published',
 *         'sort' => 'date-desc',
 *         'page' => 2,
 *         'limit' => 10,
 *     ],
 * );
 *
 * $controller = new ListArticles();
 *
 * $arguments = (new MapperBuilder())
 *     ->argumentsMapper()
 *     ->mapArguments($controller, $request);
 *
 * $response = $controller(...$arguments);
 * ```
 *
 * Example of a POST request
 * =========================
 *
 * ```
 * use CuyZ\Valinor\Mapper\Http\FromBody;
 * use CuyZ\Valinor\Mapper\Http\FromRoute;
 * use CuyZ\Valinor\Mapper\Http\HttpRequest;
 * use CuyZ\Valinor\MapperBuilder;
 *
 * // Controller to post a comment on an article
 * final class PostComment
 * {
 *     // POST /api/posts/{postId}/comments
 *     public function __invoke(
 *         // Comes from the route
 *         #[FromRoute] int $postId,
 *
 *         // Both come from body payload
 *         #[FromBody] string $author,
 *         #[FromBody] string $content,
 *     ): ResponseInterface { … }
 * }
 *
 * // POST /api/posts/1337/comments
 * $request = new HttpRequest(
 *     routeParameters: ['postId' => 1337],
 *     bodyValues: [
 *         'author' => 'jane.doe@example.com',
 *         'content' => 'Great article, thanks for sharing!',
 *     ],
 * );
 *
 * $controller = new PostComment();
 *
 * $arguments = (new MapperBuilder())
 *     ->argumentsMapper()
 *     ->mapArguments($controller, $request);
 *
 * $response = $controller(...$arguments);
  * ```
 *
 * Flattening query/body parameters
 * ================================
 *
 * Instead of mapping individual query parameters or body values to separate
 * parameters, the `mapAll` parameter can be used to map all of them at once to
 * a single parameter. This is useful when working with complex data structures
 * or when the number of parameters is large.
 *
 * ```
 * use CuyZ\Valinor\Mapper\Http\FromQuery;
 * use CuyZ\Valinor\Mapper\Http\FromRoute;
 *
 * final readonly class ArticleFilters
 * {
 *     public function __construct(
 *         public string $status,
 *         public string $sort,
 *         public int $page = 1,
 *         public int $limit = 20,
 *     ) {}
 * }
 *
 * final class ListArticles
 * {
 *     // GET /api/authors/{authorId}/articles
 *     public function __invoke(
 *         #[FromRoute] string $authorId,
 *         #[FromQuery(mapAll: true)] ArticleFilters $filters,
 *     ): ResponseInterface { … }
 * }
 * ```
 *
 * @api
 */
final class HttpRequest
{
    public function __construct(
        /**
         * Route parameters that were extracted by the router.
         *
         * @var array<mixed>
         */
        public readonly array $routeParameters = [],

        /**
         * Query parameters that were extracted from the request URI.
         *
         * @var array<mixed>
         */
        public readonly array $queryParameters = [],

        /**
         * Body values that were extracted from the request content.
         *
         * @var array<mixed>
         */
        public readonly array $bodyValues = [],

        /**
         * Original request object coming, for instance, from a library or a
         * framework. If it is given, then this object will automatically be
         * mapped to any target parameter matching its type.
         */
        public readonly ?object $requestObject = null,
    ) {}

    /**
     * @param array<mixed> $routeParameters
     */
    public static function fromPsr(ServerRequestInterface $request, array $routeParameters = []): self
    {
        if (is_object($request->getParsedBody())) {
            throw new PsrRequestParsedBodyIsObject($request->getParsedBody());
        }

        return new self($routeParameters, $request->getQueryParams(), $request->getParsedBody() ?? [], $request);
    }
}
