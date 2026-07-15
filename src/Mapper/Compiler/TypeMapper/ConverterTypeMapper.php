<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler\TypeMapper;

use CuyZ\Valinor\Compiler\Library\NewAttributeNode;
use CuyZ\Valinor\Compiler\Library\TypeAcceptNode;
use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\AttributeDefinition;
use CuyZ\Valinor\Mapper\Compiler\MappingContext;
use CuyZ\Valinor\Mapper\Compiler\Node\AddMessageNode;
use CuyZ\Valinor\Mapper\Compiler\TypeMapperFactory;
use CuyZ\Valinor\Mapper\Tree\Exception\InvalidNodeValue;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Type\Type;
use Throwable;

use function CuyZ\Valinor\Compiler\{call, closure, dumpValue, if_, logicalAnd, negate, param, return_, ternary, this, try_, value, variable};
use function implode;

/** @internal */
final class ConverterTypeMapper implements TypeMapper
{
    use TypeMapperMethodName;
    /**
     * @param array<int, array{converterIndex?: int, attrDef?: AttributeDefinition, paramType: Type, paramCount: int}> $matchingConverters
     */
    public function __construct(
        private Type $targetType,
        private TypeMapper $delegate,
        private array $matchingConverters,
    ) {}

    public function buildMappingNodes(Node $value, Node $context, Node $target): array
    {
        return [
            $target->assign(
                this()->callMethod(
                    method: $this->methodName(),
                    arguments: [
                        $value,
                        $context,
                        value(0),
                    ],
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

        // Delegate first so the type-specific method is available.
        $class = $this->delegate->manipulateMapperClass($class, $typeMapperFactory);

        return $class->withMethod(
            name: $methodName,
            parameters: [
                param('source', 'mixed'),
                param('context', MappingContext::class),
                param('from', 'int'),
            ],
            returnType: 'mixed',
            body: [
                // Processing converters
                // =====================
                //
                // Each converter is tried in order. If the source matches the
                // converter's parameter type and the converter index is
                // reachable (via $from), the converter is called and its result
                // is validated against the target type.
                ...(function () use ($methodName) {
                    foreach ($this->matchingConverters as $index => $converterInfo) {
                        yield $this->compileConverter($converterInfo, $index, $methodName);
                    }
                })(),

                // Falling through to delegate type mapper
                // ========================================
                //
                // If no converter matched, fall through to the delegate type
                // mapper (e.g. object mapper, scalar mapper, etc.).
                //
                // $delegateResult = $this->mapDelegate($source, $context);
                // return $delegateResult;
                variable('delegateResult')->assign(value(null))->asStatement(),

                ...$this->delegate->buildMappingNodes(
                    variable('source'),
                    variable('context'),
                    variable('delegateResult'),
                ),

                return_(variable('delegateResult')),
            ],
        );
    }

    /**
     * @param array{converterIndex?: int, attrDef?: AttributeDefinition, paramType: Type, paramCount: int} $converterInfo
     */
    private function compileConverter(array $converterInfo, int $index, string $methodName): Node
    {
        $paramCount = $converterInfo['paramCount'];

        // Building converter arguments
        // =============================
        //
        // Single-param converters are terminal — they directly return the
        // result after post-validation against the target type.
        //
        // Two-param converters can chain — they receive a $next closure
        // that optionally transforms the value before passing it to the
        // next converter or the delegate mapper.
        $converterArguments = $paramCount === 1
            ? [variable('source')]
            : [
                variable('source'),
                closure(
                    body: [
                        variable('newValue')->assign(
                            ternary(
                                call('func_num_args', [])->isGreaterThan(value(0)),
                                call('func_get_arg', [value(0)]),
                                variable('source'),
                            ),
                        )->asStatement(),
                        return_(
                            this()->callMethod($methodName, [
                                variable('newValue'),
                                variable('context'),
                                value($index + 1),
                            ]),
                        ),
                    ],
                    uses: ['source', 'context'],
                ),
            ];

        // Calling the converter
        // =====================
        //
        // Global converters:    $converterResult = $this->converters[$index]($source, ...)
        // Attribute converters: $converterResult = (new SomeAttribute(...))->map($source, ...)
        $converterCall = isset($converterInfo['converterIndex'])
            ? this()->access('converters')->key(value($converterInfo['converterIndex']))->call($converterArguments)
            : (new NewAttributeNode($converterInfo['attrDef']))->wrap()->callMethod('map', $converterArguments);

        return if_(
            // Checking converter applicability
            // =================================
            //
            // if ($from <= $index && $paramType->accepts($source)) {
            //     ...
            // }
            condition: logicalAnd(
                variable('from')->isLessOrEqualsTo(value($index)),
                new TypeAcceptNode(variable('source'), $converterInfo['paramType']),
            ),

            // Validating and returning the result
            // ====================================
            //
            // try {
            //     $converterResult = $converter($source);
            //     if (! $targetType->accepts($converterResult)) {
            //         $context->addMessage('invalid node value');
            //         return null;
            //     }
            //     return $converterResult;
            // } catch (Throwable $exception) { ... }
            then: try_(
                variable('converterResult')->assign($converterCall)->asStatement(),
                if_(
                    condition: negate($this->targetType->compiledAccept(variable('converterResult'))->wrap()),
                    then: [
                        new AddMessageNode(variable('context'), InvalidNodeValue::from($this->targetType), $this->targetType->toString(), dumpValue(variable('converterResult'))),
                        return_(value(null)),
                    ],
                ),
                return_(variable('converterResult')),
            )->catches(
                Throwable::class,

                // Exception handling
                // ==================
                //
                // If the context already has errors (from an inner mapping
                // failure), just return null — errors are already recorded.
                //
                // if ($context->containsErrors()) {
                //     return null;
                // }
                if_(
                    condition: variable('context')->callMethod('containsErrors'),
                    then: return_(value(null)),
                ),

                // if (! $exception instanceof Message) {
                //     $exception = ($this->exceptionFilter)($exception);
                // }
                if_(
                    condition: negate(variable('exception')->instanceOf(Message::class)),
                    then: variable('exception')->assign(
                        this()->access('exceptionFilter')->wrap()->call([variable('exception')]),
                    )->asStatement(),
                ),

                // $context->addMessage($exception, ...);
                // return null;
                new AddMessageNode(variable('context'), variable('exception'), $this->targetType->toString(), dumpValue(variable('source'))),
                return_(value(null)),
            )->asStatement(),
        );
    }

    /**
     * @return non-empty-string
     */
    private function methodName(): string
    {
        // Include converter identifiers in hash so methods with different converter
        // sets (e.g. attribute converters on different properties of the same
        // type) get distinct method names.
        $hashInput = $this->targetType->toString();

        foreach ($this->matchingConverters as $conv) {
            if (isset($conv['converterIndex'])) {
                $hashInput .= '|g' . $conv['converterIndex'];
            } elseif (isset($conv['attrDef'])) {
                $attrDef = $conv['attrDef'];
                $hashInput .= '|a' . $attrDef->class->name . '|' . implode('|', $attrDef->reflectionParts) . '|' . $attrDef->attributeIndex;
            }
        }

        return self::buildMethodName('convert_and_map', $this->targetType->toString(), $hashInput);
    }
}
