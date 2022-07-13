<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Object\Factory;

use CuyZ\Valinor\Definition\ClassDefinition;
use CuyZ\Valinor\Definition\FunctionsContainer;
use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Mapper\Object\Exception\CannotInstantiateObject;
use CuyZ\Valinor\Mapper\Object\FunctionObjectBuilder;
use CuyZ\Valinor\Mapper\Object\MethodObjectBuilder;
use CuyZ\Valinor\Mapper\Object\ObjectBuilder;
use CuyZ\Valinor\Type\Types\ClassType;

use function array_key_exists;
use function array_unshift;
use function count;

/** @internal */
final class ConstructorObjectBuilderFactory implements ObjectBuilderFactory
{
    private ObjectBuilderFactory $delegate;

    /** @var array<class-string, null> */
    public array $nativeConstructors = [];

    private FunctionsContainer $constructors;

    /** @var array<string, ObjectBuilder[]> */
    private array $builders = [];

    private ClassDefinitionRepository $classDefinitionRepository;

    /**
     * @param array<class-string, null> $nativeConstructors
     */
    public function __construct(
        ObjectBuilderFactory $delegate,
        array $nativeConstructors,
        FunctionsContainer $constructors,
        ClassDefinitionRepository $classDefinitionRepository
    ) {
        $this->delegate = $delegate;
        $this->nativeConstructors = $nativeConstructors;
        $this->constructors = $constructors;
        $this->classDefinitionRepository = $classDefinitionRepository;
    }

    public function for(ClassType $type): iterable
    {
        $class = $this->classDefinitionRepository->for($type);

        $builders = $this->listBuilders($class);

        if (count($builders) === 0) {
            if ($class->methods()->hasConstructor()) {
                throw new CannotInstantiateObject($class);
            }

            return $this->delegate->for($type);
        }

        return $builders;
    }

    /**
     * @return list<ObjectBuilder>
     */
    private function listBuilders(ClassDefinition $class): array
    {
        $type = $class->type();
        $key = $type->toString();

        if (! array_key_exists($key, $this->builders)) {
            $builders = [];

            $methods = $class->methods();

            foreach ($this->constructors as $constructor) {
                if ($constructor->returnType()->matches($type)) {
                    $builders[] = new FunctionObjectBuilder($constructor, $this->constructors->callback($constructor));
                }
            }

            if ((array_key_exists($class->name(), $this->nativeConstructors) || count($builders) === 0)
                && $methods->hasConstructor()
                && $methods->constructor()->isPublic()
            ) {
                array_unshift($builders, new MethodObjectBuilder($class, '__construct'));
            }

            $this->builders[$key] = $builders;
        }

        return $this->builders[$key];
    }
}
