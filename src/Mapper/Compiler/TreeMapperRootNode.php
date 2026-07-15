<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\TypeMapper;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\Mapper\TypeTreeMapperError;
use CuyZ\Valinor\Type\Type;

use function CuyZ\Valinor\Compiler\{anonymousClass, if_, newClass, param, property, return_, this, throw_, value, variable};

/** @internal */
final class TreeMapperRootNode extends Node
{
    public function __construct(
        private Type $type,
        private TypeMapperFactory $typeMapperFactory,
    ) {}

    public function compile(Compiler $compiler): Compiler
    {
        $typeMapper = $this->typeMapperFactory->for($this->type);

        // Build the class shell first (constructor, properties — no map method yet)
        $classNode = $this->baseClassNode();

        // Now add the map method, which calls buildMappingNodes (which may inline)
        $classNode = $this->addMapMethod($classNode, $typeMapper);

        // Add type-specific methods — this also sets runtime state like
        // ScalarTypeMapper::$allowScalarValueCasting, so it MUST run before buildMappingNodes.
        $classNode = $typeMapper->manipulateMapperClass($classNode, $this->typeMapperFactory);

        return $compiler->compile($classNode);
    }

    private function baseClassNode(): AnonymousClassNode
    {
        return anonymousClass()
            ->implements(TreeMapper::class)
            ->withArguments(
                variable('exceptionFilter'),
                variable('customConstructors'),
                variable('constructorCallbacks'),
                variable('converters'),
                variable('keyConverters'),
                variable('inferredMapping'),
            )
            ->withProperties(
                property('exceptionFilter', '\\Closure'),
                property('customConstructors', 'array'),
                property('constructorCallbacks', 'array'),
                property('converters', 'array'),
                property('keyConverters', 'array'),
                property('inferredMapping', 'array'),
            )
            ->withConstructor(
                visibility: 'public',
                parameters: [
                    param('exceptionFilter', 'callable'),
                    param('customConstructors', 'array'),
                    param('constructorCallbacks', 'array'),
                    param('converters', 'array'),
                    param('keyConverters', 'array'),
                    param('inferredMapping', 'array'),
                ],
                body: [
                    this()->access('exceptionFilter')->assign(variable('exceptionFilter'))->asStatement(),
                    this()->access('customConstructors')->assign(variable('customConstructors'))->asStatement(),
                    this()->access('constructorCallbacks')->assign(variable('constructorCallbacks'))->asStatement(),
                    this()->access('converters')->assign(variable('converters'))->asStatement(),
                    this()->access('keyConverters')->assign(variable('keyConverters'))->asStatement(),
                    this()->access('inferredMapping')->assign(variable('inferredMapping'))->asStatement(),
                ],
            );
    }

    private function addMapMethod(AnonymousClassNode $classNode, TypeMapper $typeMapper): AnonymousClassNode
    {
        return $classNode->withMethod(
            name: 'map',
            visibility: 'public',
            parameters: [
                param('signature', 'string'),
                param('source', 'mixed'),
            ],
            returnType: 'mixed',
            body: [
                variable('context')->assign(newClass(MappingContext::class))->asStatement(),
                variable('result')->assign(value(null))->asStatement(),
                ...$typeMapper->buildMappingNodes(
                    variable('source'),
                    variable('context'),
                    variable('result'),
                ),
                if_(
                    condition: variable('context')->callMethod('containsErrors'),
                    then: throw_(newClass(
                        TypeTreeMapperError::class,
                        variable('source'),
                        value($this->type->toString()),
                        variable('context')->access('messages')->callMethod('getArrayCopy'),
                    ))->asStatement(),
                ),
                return_(variable('result')),
            ],
        );
    }
}
