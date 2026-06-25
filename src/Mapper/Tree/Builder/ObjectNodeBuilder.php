<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Tree\Builder;

use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Mapper\Object\Arguments;
use CuyZ\Valinor\Mapper\Object\Exception\CannotFindObjectBuilder;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Tree\Message\ErrorMessage;
use CuyZ\Valinor\Mapper\Tree\Message\Message;
use CuyZ\Valinor\Mapper\Tree\Message\UserlandError;
use CuyZ\Valinor\Mapper\Tree\Shell;
use CuyZ\Valinor\Type\ObjectType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\UnionType;
use Throwable;

use function array_filter;
use function array_key_exists;
use function assert;
use function count;
use function is_scalar;

/** @internal */
final class ObjectNodeBuilder implements NodeBuilder
{
    public function __construct(
        private ClassDefinitionRepository $classDefinitionRepository,
        private ObjectBuilderFactory $objectBuilderFactory,
        private InterfaceNodeBuilder $interfaceNodeBuilder,
        /** @var callable(Throwable): ErrorMessage */
        private mixed $exceptionFilter,
    ) {}

    public function build(Shell $shell): Node
    {
        assert($shell->type instanceof ObjectType);

        if ($shell->type->accepts($shell->value())) {
            return $shell->node($shell->value());
        }

        $class = $this->classDefinitionRepository->for($shell->type);

        if ($this->interfaceNodeBuilder->canInferImplementation($class)) {
            return $this->interfaceNodeBuilder->build($shell);
        }

        $objectBuilders = $this->objectBuilderFactory->for($class);

        foreach ($objectBuilders as $objectBuilder) {
            $arguments = $objectBuilder->describeArguments();

            if (count($arguments) === 1 && is_scalar($shell->value())) {
                $arguments = $this->removeClassReference($arguments, $shell->type->className());
            }

            $valuesNode = $shell
                ->withType($arguments->toShapedArray())
                ->shouldApplyConverters()
                ->wrapSingleValueIfNeeded()
                ->build();

            if (! $valuesNode->isValid()) {
                if (count($objectBuilders) > 1) {
                    continue;
                }

                return $valuesNode;
            }

            try {
                /** @var array<string, mixed> */
                $values = $valuesNode->value();

                // HOTFIX: https://github.com/CuyZ/Valinor/issues/727
                // We should find a better way to handle this, and add non-regression tests
                // @infection-ignore-all
                foreach ($arguments as $argument) {
                    if (! array_key_exists($argument->name(), $values) && ! $argument->isRequired()) {
                        $values[$argument->name()] = $argument->defaultValue();
                    }
                }

                $object = $objectBuilder->buildObject($values);
            } catch (UserlandError|Message $exception) {
                if ($exception instanceof UserlandError) {
                    // @phpstan-ignore argument.type (we know there always is a previous exception)
                    $exception = ($this->exceptionFilter)($exception->getPrevious());
                }

                return $shell->error($exception);
            }

            $node = Node::new($object, $valuesNode->childrenCount());

            if ($node->isValid()) {
                return $node;
            }
        }

        return $shell->error(new CannotFindObjectBuilder());
    }

    /**
     * If the argument type is a union type, we purposely remove any subtype
     * that references the class to prevent an infinite loop due to circular
     * dependency.
     *
     * @param class-string $className
     */
    private function removeClassReference(Arguments $arguments, string $className): Arguments
    {
        $argument = $arguments->at(0);

        if (! $argument->type() instanceof UnionType) {
            return $arguments;
        }

        $subTypes = $argument->type()->types();
        $filtered = array_filter(
            $subTypes,
            static fn (Type $subType) => ! $subType instanceof ObjectType || $subType->className() !== $className
        );

        if ($filtered === $subTypes) {
            // @infection-ignore-all / No subtype was removed, so rebuilding the
            // union below would yield an equivalent type wrapped in equivalent
            // arguments; this early return only avoids that redundant work.
            return $arguments;
        }

        return new Arguments($argument->withType(UnionType::from(...$filtered)));
    }
}
