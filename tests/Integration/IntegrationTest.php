<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Tests\Integration;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Tree\Message\MessagesFlattener;
use PHPUnit\Framework\TestCase;

use function implode;

abstract class IntegrationTest extends TestCase
{
    /**
     * @return never-return
     */
    protected function mappingFail(MappingError $error)
    {
        $messages = [];

        foreach ((new MessagesFlattener($error->node()))->errors() as $message) {
            $messages[] = (string)$message->withBody('{node_path} / {node_type} / {original_message}');
        }

        self::fail(implode("\n", $messages));

//        $errorFinder = static function (Node $node, callable $errorFinder) {
//            if ($node->isValid()) {
//                return;
//            }
//
//            $errors = [];
//
//            foreach ($node->messages() as $message) {
//                if ($message->isError()) {
//                    $errors[] = (string)$message;
//                }
//            }
//
//            if (count($errors) > 0) {
//                yield $node->path() => "{$node->path()}: " . implode(' / ', $errors);
//            }
//
//            foreach ($node->children() as $child) {
//                yield from $errorFinder($child, $errorFinder);
//            }
//        };
//
//        $list = iterator_to_array($errorFinder($error->node(), $errorFinder));
//
//        self::fail(implode(' — ', $list));
    }
}
