<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper;

use CuyZ\Valinor\Cache\Cache;
use CuyZ\Valinor\Cache\CacheEntry;
use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\CacheCallbackCollector;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Compiler\TreeMapperRootNode;
use CuyZ\Valinor\Mapper\Exception\InvalidMappingTypeSignature;
use CuyZ\Valinor\Mapper\Exception\MappingLogicalException;
use CuyZ\Valinor\Mapper\Exception\TypeErrorDuringMapping;
use CuyZ\Valinor\Type\Parser\Exception\InvalidType;
use CuyZ\Valinor\Type\Parser\TypeParser;
use CuyZ\Valinor\Type\Type;

final class CacheTreeMapper implements TreeMapper
{
    public function __construct(
        private TypeParser $typeParser,
        private Cache $cache,
        private TypeMapperFactory $typeMapperFactory,
        private CacheCallbackCollector $cacheCallbackCollector,
        private Settings $settings,
    ) {}

    public function map(string $signature, mixed $source): mixed
    {
        $key = "mapper-\0" . $signature;

        try {
            $type = $this->typeParser->parse($signature);
        } catch (InvalidType $exception) {
            throw new InvalidMappingTypeSignature($signature, $exception);
        }

        // Collect FunctionObjectBuilder callbacks for this type.
        // This is needed both for fresh compilation and cache hits,
        // because callbacks (closures) cannot be serialized into the cache.
        $this->typeMapperFactory->resetCallbacks();

        try {
            $this->cacheCallbackCollector->collectCallbacksForType($type, $this->typeMapperFactory->registerCallback(...));
        } catch (MappingLogicalException $exception) {
            throw new TypeErrorDuringMapping($type, $exception);
        }

        $callbacks = $this->typeMapperFactory->constructorCallbacks();

        $mapper = $this->cache->get(
            $key,
            $this->settings->exceptionFilter,
            $callbacks,
            $this->settings->convertersSortedByPriority(),
            $this->settings->keyConverters,
            $this->settings->inferredMapping,
        );

        if ($mapper) {
            return $mapper->map($signature, $source);
        }

        try {
            // Compilation also registers callbacks via TypeMapperFactory
            $cacheEntry = new CacheEntry($this->compileFor($type)); // @todo files to watch
        } catch (MappingLogicalException $exception) {
            throw new TypeErrorDuringMapping($type, $exception);
        }

        // @phpstan-ignore argument.type (this is a temporary workaround, while waiting for the cache API to be refined)
        $this->cache->set($key, $cacheEntry);

        // Re-collect callbacks (compilation may have registered additional ones)
        $callbacks = $this->typeMapperFactory->constructorCallbacks();
        $mapper = $this->cache->get(
            $key,
            $this->settings->exceptionFilter,
            $callbacks,
            $this->settings->convertersSortedByPriority(),
            $this->settings->keyConverters,
            $this->settings->inferredMapping,
        );

        return $mapper->map($signature, $source);
    }

    private function compileFor(Type $type): string
    {
        $rootNode = new TreeMapperRootNode($type, $this->typeMapperFactory, $this->settings);

        $node = Node::shortClosure($rootNode)
            ->witParameters(
                Node::parameterDeclaration('exceptionFilter', 'callable'),
                Node::parameterDeclaration('constructorCallbacks', 'array'),
                Node::parameterDeclaration('converters', 'array'),
                Node::parameterDeclaration('keyConverters', 'array'),
                Node::parameterDeclaration('inferredMapping', 'array'),
            );

        return (new Compiler())->compile($node)->code();
    }
}
