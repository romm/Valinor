<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper;

use CuyZ\Valinor\Cache\Cache;
use CuyZ\Valinor\Cache\CacheEntry;
use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\TodoMapper;
use CuyZ\Valinor\Mapper\Compiler\TreeMapperRootNode;
use CuyZ\Valinor\Mapper\Exception\InvalidMappingTypeSignature;
use CuyZ\Valinor\Type\Parser\Exception\InvalidType;
use CuyZ\Valinor\Type\Parser\TypeParser;
use CuyZ\Valinor\Type\Type;

use function microtime;
use function var_dump;

final class CacheTreeMapper implements TreeMapper
{
    public function __construct(
        private TypeParser $typeParser,
        private Cache $cache,
        private TodoMapper $todoMapper,
        private Settings $settings,
    ) {}

    public function map(string $signature, mixed $source): mixed
    {
        $key = "mapper-\0" . $signature;

        //        $this->cache->delete($key); // @todo remove

        $time = microtime(true);
        $mapper = $this->cache->get($key, $this->settings->exceptionFilter);
        echo "CACHE GET — " . (microtime(true) - $time) * 1000 . 'ms' . PHP_EOL;

        if ($mapper) {
            //            $time = microtime(true);
            $todo = $mapper->map($signature, $source);
            //            var_dump("MAPPING — " . (microtime(true) - $time) * 1000 . 'ms');

            return $todo;
        }

        //        $time = microtime(true);
        try {
            $type = $this->typeParser->parse($signature);
        } catch (InvalidType $exception) {
            throw new InvalidMappingTypeSignature($signature, $exception);
        }
        //        var_dump("TYPE PARSING — " . (microtime(true) - $time) * 1000 . 'ms');

        $cacheEntry = new CacheEntry($this->compileFor($type)); // @todo files to watch

        // @phpstan-ignore argument.type (this is a temporary workaround, while waiting for the cache API to be refined)
        $this->cache->set($key, $cacheEntry);

        $mapper = $this->cache->get($key, $this->settings->exceptionFilter);

        return $mapper->map($signature, $source);
    }

    private function compileFor(Type $type): string
    {
        $rootNode = new TreeMapperRootNode($type, $this->todoMapper, $this->settings);

        $node = Node::shortClosure($rootNode)
            ->witParameters(
                Node::parameterDeclaration('exceptionFilter', 'callable'),
            );

        return (new Compiler())->compile($node)->code();
    }
}
