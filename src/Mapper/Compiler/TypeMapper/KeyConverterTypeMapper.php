<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\KeysCollision;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Type\Type;
use Exception;

use function array_map;
use function CuyZ\Valinor\Compiler\{call, forEach_, if_, negate, newClass, param, return_, this, try_, value, variable};

/** @internal */
final class KeyConverterTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;

    public function __construct(
        private Type $type,
        private TypeMapper $delegate,
        /** @list<int> */
        private array $keyConverterIndices,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            if_(
                condition: call('is_iterable', [variable('source')]),
                then: $target->assign(
                    this()->callMethod(
                        method: $this->methodName(),
                        arguments: [$value, $context],
                    ),
                )->asStatement(),
                else: $this->delegate->buildMappingNodes($value, $context, $target),
            ),
        ];
    }

    public function manipulateMapperClass(AnonymousClassNode $class, TypeMapperFactory $typeMapperFactory): AnonymousClassNode
    {
        $methodName = $this->methodName();

        if ($class->hasMethod($methodName)) {
            return $class;
        }

        // Register placeholder to prevent infinite recursion
        $class = $class->withMethod($methodName);

        // Delegate first so the type-specific method is available
        $class = $this->delegate->manipulateMapperClass($class, $typeMapperFactory);

        $keyVarNode = variable('newKey');

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'iterable'),
                param('context', MappingContext::class),
            ],
            returnType: 'mixed',
            body: [
                // Converting iterable to array
                // ============================
                //
                // if (! is_array($source)) {
                //     $source = iterator_to_array($source);
                // }
                if_(
                    condition: negate(call('is_array', [variable('source')])),
                    then: variable('source')->assign(
                        call('iterator_to_array', [variable('source')]),
                    )->asStatement(),
                ),

                // Initializing the converted source
                // ==================================
                //
                // $convertedSource = [];
                variable('convertedSource')->assign(value([]))->asStatement(),

                // Initializing the name map
                // =========================
                //
                // $nameMap = [];
                variable('nameMap')->assign(value([]))->asStatement(),

                // Looping over the source to convert keys
                // =======================================
                //
                // foreach ($source as $key => $value) {
                //     try {
                //         $newKey = strval($key);
                //         $newKey = $this->keyConverters[0]($newKey);
                //         $newKey = $this->keyConverters[1]($newKey);
                //         $newKey = $this->keyConverters[2]($newKey);
                //         …
                //
                //         if (array_key_exists($newKey, $nameMap)) {
                //             $context->sub(strval($key))->addMessage('key collision');
                //         } else {
                //             $convertedSource[$newKey] = $value;
                //             $nameMap[$newKey] = strval($key);
                //         }
                //     } catch (Exception $exception) {
                //         if (! $exception instanceof Message) {
                //             $exception = ($this->exceptionFilter)($exception);
                //         }
                //
                //         $context->sub(strval($key))->addMessage($exception);
                //     }
                // }
                forEach_(
                    variable('source'),
                    'key',
                    'value',
                    [
                        try_(...[
                            $keyVarNode->assign(
                                call('strval', [variable('key')]),
                            )->asStatement(),

                            // Applying key converters
                            // =======================
                            //
                            // $newKey = $this->keyConverters[0]($newKey);
                            // $newKey = $this->keyConverters[1]($newKey);
                            // $newKey = $this->keyConverters[2]($newKey);
                            // …
                            ...array_map(
                                static fn (int $index) => $keyVarNode->assign(
                                    this()->access('keyConverters')->key(value($index))->call([$keyVarNode]),
                                )->asStatement(),
                                $this->keyConverterIndices,
                            ),

                            // Checking for key collisions
                            // ===========================
                            //
                            // if (array_key_exists($newKey, $nameMap)) {
                            //     $context->sub(strval($key))->addMessage(key collision);
                            // } else {
                            //     $convertedSource[$newKey] = $value;
                            //     $nameMap[$newKey] = strval($key);
                            // }
                            if_(
                                condition: call('array_key_exists', [$keyVarNode, variable('nameMap')]),
                                then: new AddMessageNode(
                                    variable('context')->callMethod('sub', [call('strval', [variable('key')])]),
                                    newClass(KeysCollision::class, variable('nameMap')->key($keyVarNode), $keyVarNode),
                                    '?',
                                    call('strval', [variable('key')]),
                                ),
                                else: [
                                    variable('convertedSource')->key($keyVarNode)->assign(
                                        variable('value'),
                                    )->asStatement(),
                                    variable('nameMap')->key($keyVarNode)->assign(
                                        call('strval', [variable('key')]),
                                    )->asStatement(),
                                ],
                            ),
                        ])->catches(
                            Exception::class,
                            // Filtering non-Message exceptions
                            // ================================
                            //
                            // if (! $exception instanceof Message) {
                            //     $exception = ($this->exceptionFilter)($exception);
                            // }
                            if_(
                                condition: negate(variable('exception')->instanceOf(Message::class)),
                                then: variable('exception')->assign(
                                    this()->access('exceptionFilter')->wrap()->call([variable('exception')]),
                                )->asStatement(),
                            ),

                            // Adding error message for the key
                            // =================================
                            //
                            // $context->sub(strval($key))->addMessage($exception);
                            new AddMessageNode(
                                variable('context')->callMethod('sub', [call('strval', [variable('key')])]),
                                variable('exception'),
                                '?',
                                call('strval', [variable('key')]),
                            ),
                        ),
                    ],
                ),

                // Checking if errors occurred
                // ===========================
                //
                // if ($context->containsErrors()) {
                //     return null;
                // }
                if_(
                    condition: variable('context')->callMethod('containsErrors'),
                    then: return_(value(null)),
                ),

                // Setting the name map on context
                // ================================
                //
                // $context->setNameMap($nameMap);
                variable('context')->callMethod('setNameMap', [
                    variable('nameMap'),
                ])->asStatement(),

                // Delegating to the underlying type mapper
                // =========================================
                //
                // $result = $this->mapDelegate($convertedSource, $context);
                ...$this->delegate->buildMappingNodes(
                    variable('convertedSource'),
                    variable('context'),
                    variable('result'),
                ),

                // Returning the result
                // ====================
                //
                // return $result;
                return_(variable('result')),
            ],
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        return self::buildMethodName('key_convert', $this->type->toString());
    }
}
