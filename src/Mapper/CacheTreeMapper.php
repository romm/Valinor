<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper;

use CuyZ\Valinor\Cache\Cache;
use CuyZ\Valinor\Cache\CacheEntry;
use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Compiler\TreeMapperRootNode;
use CuyZ\Valinor\Mapper\Exception\InvalidMappingTypeSignature;
use CuyZ\Valinor\Type\Parser\Exception\InvalidType;
use CuyZ\Valinor\Type\Parser\TypeParser;
use CuyZ\Valinor\Type\Type;

final class CacheTreeMapper implements TreeMapper
{
    public function __construct(
        private TypeParser $typeParser,
        private Cache $cache,
        private TypeMapperFactory $typeMapperFactory,
        private Settings $settings,
    ) {}

    public function map(string $signature, mixed $source): mixed
    {
        $key = "mapper-\0" . $signature;

        $mapper = $this->cache->get($key, $this->settings->exceptionFilter);

        if ($mapper) {
            return $mapper->map($signature, $source);
        }

        try {
            $type = $this->typeParser->parse($signature);
        } catch (InvalidType $exception) {
            throw new InvalidMappingTypeSignature($signature, $exception);
        }

        $cacheEntry = new CacheEntry($this->compileFor($type)); // @todo files to watch

        // @phpstan-ignore argument.type (this is a temporary workaround, while waiting for the cache API to be refined)
        $this->cache->set($key, $cacheEntry);

        $mapper = $this->cache->get($key, $this->settings->exceptionFilter);

        return $mapper->map($signature, $source);
    }

    private function compileFor(Type $type): string
    {
        $rootNode = new TreeMapperRootNode($type, $this->typeMapperFactory, $this->settings);

        $node = Node::shortClosure($rootNode)
            ->witParameters(
                Node::parameterDeclaration('exceptionFilter', 'callable'),
            );

        return (new Compiler())->compile($node)->code();
    }
}
