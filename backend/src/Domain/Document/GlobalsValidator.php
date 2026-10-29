<?php

declare(strict_types=1);

namespace App\Domain\Document;

use function array_key_exists;
use function count;
use function in_array;
use function is_string;

/**
 * Validates the site's global styles (README §9), mirrored by the editor's `validateGlobals()`.
 *
 * Error paths follow the same rules as {@see DocumentValidator}: `globals` when the value is not an
 * object, the bare key for an unknown top-level key, `colors`/`fonts` for a malformed or oversized
 * list, `{list}.{i}[.{key}]` for an entry, `fonts.{i}.style.{key}[.{device}]` inside a preset. A
 * repeated id is reported at the later entry's `{list}.{i}.id`.
 *
 * @psalm-import-type ValidationError from DocumentValidator
 */
final readonly class GlobalsValidator
{
    private ValueValidator $values;

    public function __construct(
        private Schema $schema,
    ) {
        $this->values = new ValueValidator($schema);
    }

    /**
     * @return list<ValidationError>
     */
    public function validate(mixed $globals): array
    {
        if (!ValueValidator::isObject($globals)) {
            return [self::error('globals', 'Must be an object.')];
        }

        $errors = [];
        foreach ($globals as $key => $_) {
            if ($key !== 'colors' && $key !== 'fonts') {
                $errors[] = self::error((string) $key, 'Unknown key.');
            }
        }

        foreach (['colors' => $this->schema->maxGlobalColors, 'fonts' => $this->schema->maxGlobalFonts] as $list => $max) {
            $entries = $globals[$list] ?? null;
            if (!ValueValidator::isList($entries)) {
                $errors[] = self::error($list, 'Must be a list.');
                continue;
            }
            if (count($entries) > $max) {
                $errors[] = self::error($list, 'At most ' . $max . ' entries.');
            }
            $seen = [];
            foreach ($entries as $i => $entry) {
                $this->validateEntry($list, $i, $entry, $seen, $errors);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, true> $seen
     * @param list<ValidationError> $errors
     */
    private function validateEntry(string $list, int $i, mixed $entry, array &$seen, array &$errors): void
    {
        $path = $list . '.' . $i;
        if (!ValueValidator::isObject($entry)) {
            $errors[] = self::error($path, 'Must be an object.');

            return;
        }

        $keys = $list === 'colors' ? ['id', 'name', 'value'] : ['id', 'name', 'style'];
        foreach ($entry as $key => $_) {
            if (!in_array($key, $keys, true)) {
                $errors[] = self::error($path . '.' . $key, 'Unknown key.');
            }
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $entry)) {
                $errors[] = self::error($path . '.' . $key, 'This key is required.');
            }
        }

        $id = $entry['id'] ?? null;
        if (!is_string($id) || !$this->schema->matches('globalId', $id)) {
            $errors[] = self::error($path . '.id', 'Lowercase letters, digits and "-", starting with a letter.');
        } elseif (isset($seen[$id])) {
            $errors[] = self::error($path . '.id', 'Ids must be unique.');
        } else {
            $seen[$id] = true;
        }

        if (array_key_exists('name', $entry)) {
            $name = $entry['name'];
            if (!is_string($name) || trim($name) === '' || ValueValidator::length($name) > $this->schema->maxGlobalNameLength) {
                $errors[] = self::error($path . '.name', 'Enter a name (up to ' . $this->schema->maxGlobalNameLength . ' characters).');
            }
        }

        if ($list === 'colors' && array_key_exists('value', $entry) && ($message = $this->values->literalColor($entry['value'])) !== null) {
            $errors[] = self::error($path . '.value', $message);
        }

        if ($list === 'fonts' && array_key_exists('style', $entry)) {
            $this->validateFontStyle($path . '.style', $entry['style'], $errors);
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateFontStyle(string $path, mixed $style, array &$errors): void
    {
        if (!ValueValidator::isObject($style)) {
            $errors[] = self::error($path, 'Must be an object.');

            return;
        }

        foreach ($style as $key => $responsive) {
            $key = (string) $key;
            $spec = $this->schema->styleProperties[$key] ?? null;
            if ($spec === null || !in_array($key, $this->schema->fontKeys, true)) {
                $errors[] = self::error($path . '.' . $key, 'Not a typography style.');
                continue;
            }
            $wrapperValid = ValueValidator::isObject($responsive);
            if ($wrapperValid) {
                foreach ($responsive as $device => $_) {
                    if (!in_array($device, $this->schema->devices, true)) {
                        $wrapperValid = false;
                    }
                }
            }
            if (!$wrapperValid) {
                $errors[] = self::error($path . '.' . $key, 'A style value must map devices to values.');
                continue;
            }
            /** @var array<array-key, mixed> $responsive */
            foreach ($responsive as $device => $value) {
                if ($value !== null && ($message = $this->values->style($spec, $value)) !== null) {
                    $errors[] = self::error($path . '.' . $key . '.' . $device, $message);
                }
            }
        }
    }

    /**
     * @return ValidationError
     */
    private static function error(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }
}
