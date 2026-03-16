<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper;

use CuyZ\Valinor\Cache\Cache;
use CuyZ\Valinor\Cache\CacheEntry;
use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Library\Settings;

use CuyZ\Valinor\Mapper\Compiler\TreeMapperRootNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Exception\InvalidMappingTypeSignature;
use CuyZ\Valinor\Mapper\Exception\MappingLogicalException;
use CuyZ\Valinor\Mapper\Exception\TypeErrorDuringMapping;
use CuyZ\Valinor\Type\Parser\Exception\InvalidType;
use CuyZ\Valinor\Type\Parser\TypeParser;
use CuyZ\Valinor\Type\Type;

use function CuyZ\Valinor\Compiler\{param, shortClosure};

final class CacheTreeMapper implements TreeMapper
{
    public function __construct(
        private TypeParser $typeParser,
        private Cache $cache,
        private TypeMapperFactory $typeMapperFactory,
        /** @var array<string, mixed> */
        private array $factoryCallbacks,
        private Settings $settings,
    ) {}

    public function map(string $signature, mixed $source): mixed
    {
        $key = "mapper-\0" . $signature;

        $mapper = $this->cache->get(
            $key,
            $this->settings->exceptionFilter,
            $this->settings->customConstructors,
            $this->factoryCallbacks,
            $this->settings->convertersSortedByPriority(),
            $this->settings->keyConverters,
            $this->settings->inferredMapping,
        );

        if ($mapper) {
            return $mapper->map($signature, $source);
        }

        try {
            $type = $this->typeParser->parse($signature);
        } catch (InvalidType $exception) {
            throw new InvalidMappingTypeSignature($signature, $exception);
        }

        try {
            $cacheEntry = new CacheEntry($this->compileFor($type)); // @todo files to watch
        } catch (MappingLogicalException $exception) {
            throw new TypeErrorDuringMapping($type, $exception);
        }

        // @phpstan-ignore argument.type (this is a temporary workaround, while waiting for the cache API to be refined)
        $this->cache->set($key, $cacheEntry);

        // After compilation, merge factory callbacks with any compilation-registered callbacks
        $allCallbacks = $this->factoryCallbacks + $this->typeMapperFactory->constructorCallbacks();

        $mapper = $this->cache->get(
            $key,
            $this->settings->exceptionFilter,
            $this->settings->customConstructors,
            $allCallbacks,
            $this->settings->convertersSortedByPriority(),
            $this->settings->keyConverters,
            $this->settings->inferredMapping,
        );

        return $mapper->map($signature, $source);
    }

    private function compileFor(Type $type): string
    {
        $rootNode = new TreeMapperRootNode($type, $this->typeMapperFactory);

        $node = shortClosure(
            return: $rootNode,
            parameters: [
                param('exceptionFilter', 'callable'),
                param('customConstructors', 'array'),
                param('constructorCallbacks', 'array'),
                param('converters', 'array'),
                param('keyConverters', 'array'),
                param('inferredMapping', 'array'),
            ],
        );

        return (new Compiler())->compile($node)->code();
    }
}
