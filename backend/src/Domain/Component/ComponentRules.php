<?php

declare(strict_types=1);

namespace App\Domain\Component;

use App\Domain\Document\DocumentValidator;
use App\Domain\Document\ValueValidator;

use function count;

/**
 * What a component document must satisfy on top of the document contract (README §10): exactly
 * one top-level element, and no component instances inside (components do not nest).
 *
 * @psalm-import-type ValidationError from DocumentValidator
 */
final readonly class ComponentRules
{
    public function __construct(
        private DocumentValidator $validator,
    ) {}

    /**
     * @return list<ValidationError>
     */
    public function validate(mixed $document, ?int $rawBytes = null): array
    {
        $errors = $this->validator->validate($document, $rawBytes);
        if ($errors !== []) {
            return $errors;
        }

        /** @var array<array-key, mixed> $document */
        if (count(ValueValidator::asList($document['rootIds'] ?? null)) !== 1) {
            $errors[] = ['path' => 'rootIds', 'message' => 'A component must have exactly one top-level element.'];
        }
        foreach (ValueValidator::asObject($document['nodes'] ?? null) as $id => $node) {
            if ((ValueValidator::asObject($node)['type'] ?? null) === 'component') {
                $errors[] = ['path' => 'nodes.' . $id . '.type', 'message' => 'Components cannot contain other components.'];
            }
        }

        return $errors;
    }
}
