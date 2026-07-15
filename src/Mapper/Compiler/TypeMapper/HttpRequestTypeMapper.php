<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Compiler\HttpRequestResolver;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Http\FromBody;
use CuyZ\Valinor\Mapper\Http\FromQuery;
use CuyZ\Valinor\Mapper\Http\FromRoute;
use CuyZ\Valinor\Mapper\Http\HttpRequest;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotUseBothFromBodyAttributes;
use CuyZ\Valinor\Mapper\Tree\Exception\CannotUseBothFromQueryAttributes;
use CuyZ\Valinor\Type\Types\ShapedArrayElement;
use CuyZ\Valinor\Type\Types\ShapedArrayType;

use function CuyZ\Valinor\Compiler\{array_, call, if_, logicalAnd, negate, newClass, param, return_, this, value, variable};

/**
 * Fills a shaped array with the values of an HTTP request, the same way
 * `HttpRequestNodeBuilder` does for the runtime mapper.
 *
 * Any other source is left to the delegate.
 *
 * @internal
 */
final class HttpRequestTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;

    public function __construct(
        private ShapedArrayType $type,
        private TypeMapper $delegate,
        private ?string $variant = null,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            $target->assign(
                this()->callMethod(
                    method: $this->methodName(),
                    arguments: [$value, $context],
                ),
            )->asStatement(),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        // Register a placeholder method to prevent infinite recursion.
        $class = $class->withMethod($methodName);

        $class = $this->delegate->manipulateMapperClass($class, $typeMapperFactory);

        $plan = $this->plan();

        // The elements that are not filled by a whole query or body are mapped
        // through a shaped array, as any other source would be. The whole
        // subtree is compiled with the settings an HTTP request requires, and
        // therefore lives under its own variant.
        $httpFactory = $typeMapperFactory->forHttpRequest();

        $httpMapper = new ShapedArrayTypeMapper(
            new ShapedArrayType($plan['elements']),
            $httpFactory->settings(),
            $httpFactory->variant(),
        );

        $class = $httpMapper->manipulateMapperClass($class, $httpFactory);

        $body = [
            // Any source other than an HTTP request is mapped as usual.
            //
            // if (! $source instanceof HttpRequest) {
            //     $result = $this->mapShapedArray($source, $context);
            //     return $result;
            // }
            if_(
                condition: negate(variable('source')->instanceOf(HttpRequest::class)),
                then: [
                    variable('delegateResult')->assign(value(null))->asStatement(),
                    ...$this->delegate->buildMappingNodes(
                        variable('source'),
                        variable('context'),
                        variable('delegateResult'),
                    ),
                    return_(variable('delegateResult')),
                ],
            ),

            // $result = [];
            variable('result')->assign(value([]))->asStatement(),

            // Spreading the request values over the elements
            // =============================================
            //
            // $resolver = new HttpRequestResolver();
            // $values = $resolver->resolve($source, $context, [...], false, false);
            //
            // if ($values === null) {
            //     return null;
            // }
            variable('resolver')->assign(newClass(HttpRequestResolver::class))->asStatement(),

            variable('values')->assign(
                variable('resolver')->callMethod('resolve', [
                    variable('source'),
                    variable('context'),
                    array_($plan['sources']),
                    value($plan['queryAsRoot'] !== null),
                    value($plan['bodyAsRoot'] !== null),
                ]),
            )->asStatement(),

            if_(
                condition: variable('values')->equals(value(null)),
                then: return_(value(null)),
            ),

            // Elements taking a whole query or body, and elements taking the
            // request object, are handled apart from the shaped array.
            ...$this->rootElementNodes($plan, $class, $typeMapperFactory),
            ...$this->requestObjectNodes($plan),

            // $mapped = $this->mapShapedArrayForHttpRequest($values, $context);
            variable('mapped')->assign(value(null))->asStatement(),

            ...$httpMapper->buildMappingNodes(
                variable('values'),
                variable('context'),
                variable('mapped'),
            ),

            if_(
                condition: variable('context')->callMethod('containsErrors'),
                then: return_(value(null)),
            ),

            // The elements handled apart never overlap with the mapped ones, so
            // this is the same as `$result + $mapped`.
            //
            // return array_replace($mapped, $result);
            return_(call('array_replace', [variable('mapped'), variable('result')])),
        ];

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
            ],
            returnType: '?' . $this->type->nativeType()->toString(),
            body: $body,
        );
    }

    /**
     * Splits the elements depending on the request source they are filled from.
     * This only depends on the attributes of the elements, and is therefore
     * fully known at compile time.
     *
     * @return array{
     *     elements: array<ShapedArrayElement>,
     *     sources: array<string, Node>,
     *     queryAsRoot: ShapedArrayElement|null,
     *     bodyAsRoot: ShapedArrayElement|null,
     *     requestObjectKeys: list<ShapedArrayElement>,
     * }
     */
    private function plan(): array
    {
        $elements = [];
        $sources = [];
        $requestObjectKeys = [];
        $queryAsRoot = null;
        $bodyAsRoot = null;
        $queryAttributes = 0;
        $bodyAttributes = 0;

        foreach ($this->type->elements as $key => $element) {
            $attributes = $element->attributes();
            $key = (string)$key;

            if ($attributes->has(FromRoute::class)) {
                $sources[$key] = value(HttpRequestResolver::FROM_ROUTE);
                $elements[$key] = $element;
            } elseif ($attributes->has(FromQuery::class)) {
                /** @var FromQuery $attribute */
                $attribute = $attributes->firstOf(FromQuery::class)->instantiate();

                $queryAttributes++;

                if ($attribute->asRoot) {
                    $queryAsRoot = $element;
                } else {
                    $sources[$key] = value(HttpRequestResolver::FROM_QUERY);
                    $elements[$key] = $element;
                }

                // No other `#[FromQuery]` element is allowed alongside.
                if ($queryAsRoot !== null && $queryAttributes > 1) {
                    throw new CannotUseBothFromQueryAttributes();
                }
            } elseif ($attributes->has(FromBody::class)) {
                /** @var FromBody $attribute */
                $attribute = $attributes->firstOf(FromBody::class)->instantiate();

                $bodyAttributes++;

                if ($attribute->asRoot) {
                    $bodyAsRoot = $element;
                } else {
                    $sources[$key] = value(HttpRequestResolver::FROM_BODY);
                    $elements[$key] = $element;
                }

                // No other `#[FromBody]` element is allowed alongside.
                if ($bodyAsRoot !== null && $bodyAttributes > 1) {
                    throw new CannotUseBothFromBodyAttributes();
                }
            } else {
                $sources[$key] = value(HttpRequestResolver::FROM_ANY);
                $elements[$key] = $element;
                $requestObjectKeys[] = $element;
            }
        }

        return [
            'elements' => $elements,
            'sources' => $sources,
            'queryAsRoot' => $queryAsRoot,
            'bodyAsRoot' => $bodyAsRoot,
            'requestObjectKeys' => $requestObjectKeys,
        ];
    }

    /**
     * @param array{queryAsRoot: ShapedArrayElement|null, bodyAsRoot: ShapedArrayElement|null, ...} $plan
     * @return list<Node>
     */
    private function rootElementNodes(array $plan, AnonymousClassNode &$class, TypeMapperFactory $typeMapperFactory): array
    {
        $nodes = [];

        foreach (['queryAsRoot' => 'queryParameters', 'bodyAsRoot' => 'bodyValues'] as $name => $property) {
            $element = $plan[$name];

            if ($element === null) {
                continue;
            }

            $mapper = $typeMapperFactory->for($element->type(), attributes: $element->attributes());
            $class = $mapper->manipulateMapperClass($class, $typeMapperFactory);

            $key = $element->key()->value();

            // $result['query'] = $this->mapQueryType($source->queryParameters, $context->sub('query'));
            $nodes = [
                ...$nodes,
                ...$mapper->buildMappingNodes(
                    variable('source')->access($property),
                    variable('context')->callMethod('sub', [value((string)$key)]),
                    variable('result')->key(value($key)),
                ),
            ];
        }

        return $nodes;
    }

    /**
     * An element whose type accepts the request object is filled with it. The
     * value is injected in the values the shaped array is mapped with, so that
     * the element goes through its own mapper, which lets the object through.
     *
     * @param array{requestObjectKeys: list<ShapedArrayElement>, ...} $plan
     * @return list<Node>
     */
    private function requestObjectNodes(array $plan): array
    {
        $nodes = [];

        foreach ($plan['requestObjectKeys'] as $element) {
            $key = $element->key()->value();

            // if ($source->requestObject !== null && $source->requestObject instanceof SomeRequest) {
            //     $values['request'] = $source->requestObject;
            // }
            $nodes[] = if_(
                condition: logicalAnd(
                    variable('source')->access('requestObject')->different(value(null)),
                    $element->type()->compiledAccept(variable('source')->access('requestObject'))->wrap(),
                ),
                then: variable('values')->key(value($key))->assign(
                    variable('source')->access('requestObject'),
                )->asStatement(),
            );
        }

        return $nodes;
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('map_http_request', $this->type->toString(), $this->variantHashInput($this->type->toString()));
    }
}
