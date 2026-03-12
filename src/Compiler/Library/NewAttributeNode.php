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
use function CuyZ\Valinor\Compiler\{className, newClass, value};
use function str_contains;

/** @internal */
final class NewAttributeNode extends Node
{
    public function __construct(private AttributeDefinition $attribute) {}

    public function compile(Compiler $compiler): Compiler
    {
        if ($this->attribute->arguments !== null) {
            return $compiler->compile(
                newClass(
                    $this->attribute->class->name,
                    ...array_map(value(...), $this->attribute->arguments),
                ),
            );
        }

        $classNameNode = $this->classNameNode($this->attribute->reflectionParts[1]);

        // @phpstan-ignore match.unhandled (closure/closureParameter cannot be compiled statically)
        $reflectorNode = match ($this->attribute->reflectionParts[0]) {
            'class' => newClass(ReflectionClass::class, $classNameNode)->wrap(),
            'property' => newClass(ReflectionProperty::class, $classNameNode, value($this->attribute->reflectionParts[2]))->wrap(),
            'method' => newClass(ReflectionMethod::class, $classNameNode, value($this->attribute->reflectionParts[2]))->wrap(),
            'methodParameter' => newClass(ReflectionMethod::class, $classNameNode, value($this->attribute->reflectionParts[2]))
                ->wrap()->callMethod('getParameters')->key(value($this->attribute->reflectionParts[3])),
        };

        return $compiler->compile(
            $reflectorNode
                ->callMethod('getAttributes')
                ->key(value($this->attribute->attributeIndex))
                ->callMethod('newInstance'),
        );
    }

    /**
     * For named classes, use className() which generates \ClassName, then
     * asClassConstant() to generate \ClassName::class.
     * For anonymous classes (containing '@'), use value() which generates
     * a string literal that ReflectionClass/ReflectionProperty/etc. accept.
     */
    private function classNameNode(string $className): Node
    {
        if (str_contains($className, '@')) {
            return value($className);
        }

        return className($className)->asClassConstant();
    }
}
