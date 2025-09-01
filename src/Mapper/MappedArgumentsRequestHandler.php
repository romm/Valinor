<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper;

use CuyZ\Valinor\Definition\Repository\FunctionDefinitionRepository;
use CuyZ\Valinor\Mapper\Exception\TypeErrorDuringArgumentsMapping;
use CuyZ\Valinor\Mapper\Tree\Builder\RootNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Exception\UnresolvableShellType;
use CuyZ\Valinor\Mapper\Tree\Shell;
use CuyZ\Valinor\Type\Types\InterfaceType;
use CuyZ\Valinor\Type\Types\ShapedArrayElement;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class MappedArgumentsRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private RootNodeBuilder $flexibleBuilder,
        private RootNodeBuilder $strictBuilder,
        private FunctionDefinitionRepository $functionDefinitionRepository,
        /** @var callable */
        private mixed $controller, // @todo should be put in a request attribute instead
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $functionDefinition = $this->functionDefinitionRepository->for($this->controller);

        if (! (new InterfaceType(ResponseInterface::class))->matches($functionDefinition->returnType)) {
            throw new RuntimeException('@todo');
        }

        $attributes = $request->getAttributes();
        $body = $request->getMethod() === 'GET'
            ? $request->getQueryParams()
            : [...$request->getUploadedFiles(), ...$request->getParsedBody()]; // @todo handle null/object

        $arguments = [];
        $attributeElements = [];
        $bodyElements = [];

        foreach ($functionDefinition->parameters as $parameter) {
            // @todo handle RequestInterface
            if ($parameter->type instanceof InterfaceType && $parameter->type->className() === ServerRequestInterface::class) {
                $arguments[$parameter->name] = $request;
            } elseif (isset($attributes[$parameter->name])) {
                $attributeElements[] = ShapedArrayElement::fromParameter($parameter);
            } else {
                $bodyElements[] = ShapedArrayElement::fromParameter($parameter);
            }
        }

        if ($attributeElements !== []) {
            $mappedAttributes = $this->map($this->flexibleBuilder, $attributeElements, $attributes);

            $arguments = [...$mappedAttributes, ...$arguments];
        }

        if ($body !== []) {
            if (count($bodyElements) === 1) {
                $element = $bodyElements[0];

                if (! isset($body[$element->key()->value()])) { // @todo other cases?
                    $body = [$element->key()->value() => $body];
                }
            }

            $nodeBuilder = $request->getMethod() === 'GET' ? $this->flexibleBuilder : $this->strictBuilder;

            $mappedBody = $this->map($nodeBuilder, $bodyElements, $body);

            $arguments = [...$mappedBody, ...$arguments];
        }

        return ($this->controller)(...$arguments);
    }

    private function map(RootNodeBuilder $nodeBuilder, array $elements, array $value): mixed
    {
        $shell = Shell::root(new ShapedArrayType(...$elements), $value);

        try {
            $node = $nodeBuilder->build($shell);
        } catch (UnresolvableShellType $exception) {
            throw new TypeErrorDuringArgumentsMapping($this->controller, $exception);
        }

        if (! $node->isValid()) {
            throw new RuntimeException('@todo');
        }

        return $node->value();
    }
}
