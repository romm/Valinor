<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Native\AnonymousClassNode;
use CuyZ\Valinor\Compiler\Native\MethodNode;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Library\Settings;
use CuyZ\Valinor\Mapper\Compiler\TypeMapper\TypeMapper;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\Mapper\TypeTreeMapperError;
use CuyZ\Valinor\Type\Type;

final class TreeMapperRootNode extends Node
{
    public function __construct(
        private Type $type,
        private TypeMapperFactory $typeMapperFactory,
        private Settings $settings,
    ) {}

    public function compile(Compiler $compiler): Compiler
    {
        $typeMapper = $this->typeMapperFactory->for($this->type);

        $classNode = $this->mapperClassNode($typeMapper);
        $classNode = $typeMapper->manipulateMapperClass($classNode, $this->settings, $this->typeMapperFactory);

        return $compiler->compile($classNode);
    }

    private function mapperClassNode(TypeMapper $typeMapper): AnonymousClassNode
    {
        return Node::anonymousClass()
            ->implements(TreeMapper::class)
            ->withArguments(
                Node::variable('exceptionFilter'),
                Node::variable('constructorCallbacks'),
            )
            ->withProperties(
                Node::propertyDeclaration('constructorCallbacks', 'array'),
                Node::propertyDeclaration('exceptionFilter', '\\Closure'),
            )
            ->withMethods(
                MethodNode::constructor()
                    ->withVisibility('public')
                    ->witParameters(
                        Node::parameterDeclaration('exceptionFilter', 'callable'),
                        Node::parameterDeclaration('constructorCallbacks', 'array'),
                    )
                    ->withBody(
                        Node::property('constructorCallbacks')->assign(Node::variable('constructorCallbacks'))->asExpression(),
                        Node::property('exceptionFilter')->assign(Node::variable('exceptionFilter'))->asExpression(),
                    ),
                Node::method('map')
                    ->withVisibility('public')
                    ->witParameters(
                        Node::parameterDeclaration('signature', 'string'),
                        Node::parameterDeclaration('source', 'mixed'),
                    )
                    ->withReturnType('mixed')
                    ->withBody(
                        Node::variable('context')->assign(Node::newClass(MappingContext::class))->asExpression(),
                        Node::variable('result')->assign(
                            $typeMapper->formatValueNode(
                                Node::variable('source'),
                                Node::variable('context'),
                            ),
                        )->asExpression(),
                        Node::if(
                            condition: Node::variable('context')->callMethod('containsErrors'),
                            body: Node::throw(Node::newClass(
                                TypeTreeMapperError::class,
                                Node::variable('source'),
                                Node::value($this->type->toString()),
                                Node::variable('context')->access('messages')->callMethod('getArrayCopy'),
                            ))->asExpression(),
                        ),
                        Node::return(Node::variable('result')),
                    ),
            );
    }
}
