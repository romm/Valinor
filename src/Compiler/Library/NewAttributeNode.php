<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Compiler\Library;

use CuyZ\Valinor\Compiler\Compiler;
use CuyZ\Valinor\Compiler\Node;
use CuyZ\Valinor\Definition\AttributeDefinition;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

use function array_map;
use function str_contains;

/** @internal */
final class NewAttributeNode extends Node
{
    public function __construct(private AttributeDefinition $attribute) {}

    public function compile(Compiler $compiler): Compiler
    {
        if ($this->attribute->arguments !== null) {
            return $compiler->compile(
                Node::newClass(
                    $this->attribute->class->name,
                    ...array_map(Node::value(...), $this->attribute->arguments),
                ),
            );
        }

        $classNameNode = $this->classNameNode($this->attribute->reflectionParts[1]);

        // @phpstan-ignore match.unhandled (closure/closureParameter cannot be compiled statically)
        $reflectorNode = match ($this->attribute->reflectionParts[0]) {
            'class' => Node::newClass(ReflectionClass::class, $classNameNode)->wrap(),
            'property' => Node::newClass(ReflectionProperty::class, $classNameNode, Node::value($this->attribute->reflectionParts[2]))->wrap(),
            'method' => Node::newClass(ReflectionMethod::class, $classNameNode, Node::value($this->attribute->reflectionParts[2]))->wrap(),
            'methodParameter' => Node::newClass(ReflectionMethod::class, $classNameNode, Node::value($this->attribute->reflectionParts[2]))
                ->wrap()->callMethod('getParameters')->key(Node::value($this->attribute->reflectionParts[3])),
        };

        return $compiler->compile(
            $reflectorNode
                ->callMethod('getAttributes')
                ->key(Node::value($this->attribute->attributeIndex))
                ->callMethod('newInstance'),
        );
    }

    /**
     * For named classes, use Node::className() which generates \ClassName::class.
     * For anonymous classes (containing '@'), use Node::value() which generates
     * a string literal that ReflectionClass/ReflectionProperty/etc. accept.
     */
    private function classNameNode(string $className): Node
    {
        if (str_contains($className, '@')) {
            return Node::value($className);
        }

        return Node::className($className);
    }
}
