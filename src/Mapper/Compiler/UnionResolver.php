<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Mapper\Compiler;

use CuyZ\Valinor\Mapper\Tree\Exception\CannotResolveTypeFromUnion;
use CuyZ\Valinor\Mapper\Tree\Exception\TooManyResolvedTypesFromUnion;
use CuyZ\Valinor\Utility\ValueDumper;

use function count;
use function krsort;
use function reset;
use function usort;

/**
 * Resolves a union type by analyzing multiple candidate results.
 *
 * This class implements the union resolution algorithm that prioritizes
 * candidates based on:
 * - Error priority (objects > arrays > scalars/null)
 * - Struct specificity (more children = more specific)
 * - Scalar priority (int > float > string > bool)
 *
 * @internal
 */
final class UnionResolver
{
    /**
     * Resolve a union type at runtime.
     *
     * Each candidate has:
     * - result: the mapped value
     * - context: isolated MappingContext
     * - category: 'struct', 'scalar', or 'other'
     * - errorPriority: TypeHelper::typePriority for error grouping (object=3, array=2, scalar/null=1)
     * - scalarPriority: TypeHelper::scalarTypePriority for scalar resolution (int=4, float=3, string=2, bool=1)
     * - children: argument/element count for struct specificity
     *
     * @param list<array{result: mixed, context: MappingContext, category: string, errorPriority: int, scalarPriority: int, children: int}> $candidates
     */
    public function resolve(
        MappingContext $parentContext,
        array $candidates,
        mixed $source,
        string $unionType,
        string $expectedSignature,
    ): mixed {
        $valid = [];
        $structs = [];
        $scalars = [];
        $errors = [];

        foreach ($candidates as $candidate) {
            if ($candidate['context']->containsErrors()) {
                $errors[$candidate['errorPriority']][] = $candidate;
                continue;
            }

            $valid[] = $candidate;

            if ($candidate['category'] === 'struct') {
                $structs[] = $candidate;
            } elseif ($candidate['category'] === 'scalar') {
                $scalars[] = $candidate;
            }
        }

        if ($valid === []) {
            // No valid match: pick the error from the highest-priority type
            krsort($errors);

            if ($errors !== [] && count(reset($errors)) === 1) {
                // Single error from the highest-priority type: merge its errors
                $best = reset($errors)[0];
                $parentContext->mergeFrom($best['context']);
                return $best['result'];
            }

            // Multiple errors or no priority winner: general union error
            if ($source === null) {
                $parentContext->addMessage(
                    new CannotResolveTypeFromUnion(null),
                    $unionType,
                    '*missing*',
                    $expectedSignature,
                );
            } else {
                $parentContext->addMessage(
                    new CannotResolveTypeFromUnion($source),
                    $unionType,
                    ValueDumper::dump($source),
                    $expectedSignature,
                );
            }

            return null;
        }

        if (count($valid) === 1) {
            return $valid[0]['result'];
        }

        // If there is only one scalar and one struct, the scalar has priority
        if (count($scalars) === 1 && count($structs) === 1) {
            return $scalars[0]['result'];
        }

        if ($structs !== []) {
            // Pick struct with most children (most specific)
            $childrenCount = [];
            foreach ($structs as $struct) {
                $childrenCount[$struct['children']][] = $struct;
            }
            krsort($childrenCount);
            $first = reset($childrenCount);

            if (count($first) === 1) {
                return $first[0]['result'];
            }
        } elseif ($scalars !== []) {
            // Sort by scalar type priority (int > float > string > bool)
            usort($scalars, static fn (array $a, array $b): int => $b['scalarPriority'] <=> $a['scalarPriority']);
            return $scalars[0]['result'];
        }

        // Too many resolved types: collision
        $parentContext->addMessage(
            new TooManyResolvedTypesFromUnion(),
            $unionType,
            ValueDumper::dump($source),
            $expectedSignature,
        );

        return null;
    }
}
