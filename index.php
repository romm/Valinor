<?php

namespace ZZZ;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Printer\HtmlNodePrinter;
use CuyZ\Valinor\Mapper\Tree\Message\Formatter\MessageMapFormatter;
use CuyZ\Valinor\Mapper\Tree\Message\NodeMessage;
use CuyZ\Valinor\MapperBuilder;
use DateTimeInterface;
use RuntimeException;

use function var_dump;

require_once 'vendor/autoload.php';

$source = [
    'foo' => 'FOO!',
    'boolz' => true,
    'someInt' => '42',
    'date' => 'ezzz',
    'b' => [
        [
            'bar' => 'BAR?',
            'c' => [1 => [true, 'BAZ!'], ['BAZ?', 'BAZ!'], ['BAZ?', 'BAZ!'],],
            'd' => [1 => ['BAZ?', 'BAZ!'], ['BAZ?', 'BAZ!'], ['BAZ?', 'BAZ!'],],
        ],
        [
            'bar' => 'BAR!',
            'c' => [['BAZ?', 'BAZ!']],
        ],
        [
            'bar' => 'BAR?',
            'c' => [['BAZ?', 'BAZ!']],
        ],
        [
            'bar' => 'BAR!',
            'c' => [['BAZ?', 'BAZ!']],
        ],
    ],
];
$node = (new MapperBuilder())
    ->withCacheDir(__DIR__ . '/var/')
    ->node()
    ->map(A::class, $source);

$formatter = (new MessageMapFormatter([
    'mon_code' => fn (NodeMessage $message) => $message->format($this->translate('zzzmmzmzmzzmm ')),
    1630686564 => fn (NodeMessage $message) => $message->format('previous: %3$s'),
    RuntimeException::class => function (NodeMessage $message) {
        if ($message->originalMessage()->getMessage() === 'foo') {
        } elseif ($message->originalMessage()->getMessage() === 'bar') {
        }

        return $message->format('zzzmmzmzmzzmm %1$s', 'pouet');
    },
]))->defaultsTo(/*fn(NodeMessage $message) => */ 'default message %1$s / code: %2$s / type: %3$s');

final class Foo
{
    public function __construct(private string $someValue)
    {
        throw new \RuntimeException('@todo'); // @todo
        var_dump($someValue);
    }
}

try {
    (new MapperBuilder())->mapper()->map(Foo::class, 'zzz');
} catch (MappingError $error) {
    ray($error);
}

echo '<link rel="stylesheet" href="src/Mapper/Printer/test.css" />';
echo (new HtmlNodePrinter())
    ->withMessageFormatter($formatter)
    ->toHtml($node);

final class A
{
    public string $foo;

    public int $someInt;

    /** @var B[] */
    public array $b;

    public bool $boolz;

    public DateTimeInterface $date;
}

final class B
{
    public string $bar;

    /** @var C[] */
    public array $c;

    /** @var D[] */
    public array $d;
}

final class C
{
    /** @var string[] */
    public array $baz;
}

final class D
{
    /** @var string[] */
    public array $baz;
}
