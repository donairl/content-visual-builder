<?php

declare(strict_types=1);

namespace App\Domain\Document;

use function array_key_exists;
use function count;
use function in_array;
use function is_string;

/**
 * Validates a page document against the shared contract (README §1–§3).
 *
 * Error paths are part of the contract: the editor's validator must report the same path for every
 * case in `schema/fixtures/validation/`. The rules that decide a path:
 *
 * - unknown top-level keys are reported at the bare key (`extra`), unknown nested keys at
 *   `{parent}.{key}`;
 * - reachability is a depth-first pre-order walk from `rootIds`; the *second* encounter of an id (a
 *   shared child or a cycle) and every node never reached (an orphan) are reported at `nodes.{id}`;
 * - a node deeper than `limits.maxDepth` is reported at `nodes.{id}` (every such node);
 * - a dangling reference is reported where it is written (`rootIds`, `nodes.{id}.children`);
 * - a style value failing its type is reported per device (`nodes.{id}.style.{key}.{device}`), but a
 *   malformed responsive wrapper (unknown device, not an object) at `nodes.{id}.style.{key}`;
 * - link/image props are validated as a whole and reported at `nodes.{id}.props.{key}`.
 *
 * @psalm-type ValidationError = array{path: string, message: string}
 */
final readonly class DocumentValidator
{
    private const DOCUMENT_KEYS = ['schemaVersion', 'rootIds', 'nodes', 'settings'];
    private const NODE_KEYS = ['id', 'type', 'props', 'style', 'advanced', 'children'];
    private const ADVANCED_KEYS = ['cssId', 'cssClasses', 'hideOn', 'customCss'];

    private ValueValidator $values;

    public function __construct(
        private Schema $schema,
    ) {
        $this->values = new ValueValidator($schema);
    }

    /**
     * @param int|null $rawBytes Size of the JSON as received; measured from a re-encoding when absent.
     *
     * @return list<ValidationError>
     */
    public function validate(mixed $document, ?int $rawBytes = null): array
    {
        $rawBytes ??= DocumentJson::byteSize($document);
        if ($rawBytes > $this->schema->maxDocumentBytes) {
            // Nothing else is checked: the size limit exists to bound the work done on a document.
            return [self::error('document', 'The document exceeds ' . $this->schema->maxDocumentBytes . ' bytes.')];
        }

        if (!ValueValidator::isObject($document)) {
            return [self::error('document', 'The document must be an object.')];
        }

        $errors = [];

        foreach ($document as $key => $_) {
            if (!in_array($key, self::DOCUMENT_KEYS, true)) {
                $errors[] = self::error((string) $key, 'Unknown key.');
            }
        }
        foreach (self::DOCUMENT_KEYS as $key) {
            if (!array_key_exists($key, $document)) {
                $errors[] = self::error($key, 'This key is required.');
            }
        }

        if (array_key_exists('schemaVersion', $document) && $document['schemaVersion'] !== $this->schema->schemaVersion
            && !(ValueValidator::isNumber($document['schemaVersion']) && (float) $document['schemaVersion'] === (float) $this->schema->schemaVersion)
        ) {
            $errors[] = self::error('schemaVersion', 'Unsupported schema version; expected ' . $this->schema->schemaVersion . '.');
        }

        if (array_key_exists('settings', $document)) {
            $this->validateSettings($document['settings'], $errors);
        }

        /** @var array<string, array<array-key, mixed>> $nodes Valid-looking node objects by id. */
        $nodes = [];
        if (array_key_exists('nodes', $document)) {
            $rawNodes = $document['nodes'];
            if (!ValueValidator::isObject($rawNodes)) {
                $errors[] = self::error('nodes', 'Must be an object keyed by node id.');
            } else {
                if (count($rawNodes) > $this->schema->maxNodes) {
                    $errors[] = self::error('nodes', 'A document may contain at most ' . $this->schema->maxNodes . ' nodes.');
                }
                foreach ($rawNodes as $key => $node) {
                    $id = (string) $key;
                    $nodes[$id] = $this->validateNode($id, $node, $rawNodes, $errors);
                }
            }
        }

        $rootIds = [];
        if (array_key_exists('rootIds', $document)) {
            $raw = $document['rootIds'];
            if (!ValueValidator::isList($raw)) {
                $errors[] = self::error('rootIds', 'Must be a list of node ids.');
            } else {
                foreach ($raw as $rootId) {
                    if (!is_string($rootId)) {
                        $errors[] = self::error('rootIds', 'Node ids must be strings.');
                    } elseif (!array_key_exists($rootId, $nodes)) {
                        $errors[] = self::error('rootIds', 'Unknown node "' . $rootId . '".');
                    } else {
                        $rootIds[] = $rootId;
                    }
                }
            }
        }

        $this->validateTree($rootIds, $nodes, $errors);

        return self::unique($errors);
    }

    /**
     * Depth-first pre-order walk from the roots: duplicates, cycles, depth and orphans.
     *
     * @param list<string> $rootIds Roots that exist in `nodes`.
     * @param array<string, array<array-key, mixed>> $nodes
     * @param list<ValidationError> $errors
     */
    private function validateTree(array $rootIds, array $nodes, array &$errors): void
    {
        $visited = [];
        /** @var list<array{string, int}> $stack */
        $stack = [];
        for ($i = count($rootIds) - 1; $i >= 0; $i--) {
            $stack[] = [$rootIds[$i], 1];
        }

        while ($stack !== []) {
            [$id, $depth] = array_pop($stack);

            if (isset($visited[$id])) {
                $errors[] = self::error('nodes.' . $id, 'The node is reachable more than once (shared child or cycle).');
                continue;
            }
            $visited[$id] = true;

            if ($depth > $this->schema->maxDepth) {
                $errors[] = self::error('nodes.' . $id, 'The node is nested deeper than ' . $this->schema->maxDepth . ' levels.');
            }

            $children = self::childIds($nodes[$id] ?? []);
            for ($i = count($children) - 1; $i >= 0; $i--) {
                if (array_key_exists($children[$i], $nodes)) {
                    $stack[] = [$children[$i], $depth + 1];
                }
            }
        }

        foreach ($nodes as $id => $_) {
            if (!isset($visited[$id])) {
                $errors[] = self::error('nodes.' . $id, 'The node is not reachable from rootIds.');
            }
        }
    }

    /**
     * Validates one entry of `nodes` and returns it as an array (empty if it is not an object), so the
     * tree walk can still follow its children.
     *
     * @param array<array-key, mixed> $allNodes
     * @param list<ValidationError> $errors
     *
     * @return array<array-key, mixed>
     */
    private function validateNode(string $id, mixed $node, array $allNodes, array &$errors): array
    {
        $base = 'nodes.' . $id;

        if (!$this->schema->matches('nodeId', $id)) {
            $errors[] = self::error($base, 'Node ids must be 8 characters of a-z and 0-9.');
        }

        if (!ValueValidator::isObject($node)) {
            $errors[] = self::error($base, 'The node must be an object.');

            return [];
        }

        foreach ($node as $key => $_) {
            if (!in_array($key, self::NODE_KEYS, true)) {
                $errors[] = self::error($base . '.' . $key, 'Unknown key.');
            }
        }
        foreach (self::NODE_KEYS as $key) {
            if (!array_key_exists($key, $node)) {
                $errors[] = self::error($base . '.' . $key, 'This key is required.');
            }
        }

        if (array_key_exists('id', $node) && $node['id'] !== $id) {
            $errors[] = self::error($base . '.id', 'The id must equal its key in "nodes".');
        }

        $manifest = null;
        if (array_key_exists('type', $node)) {
            $manifest = is_string($node['type']) ? $this->schema->widget($node['type']) : null;
            if ($manifest === null) {
                $errors[] = self::error($base . '.type', 'Unknown widget type.');
            }
        }

        if (array_key_exists('children', $node)) {
            $children = $node['children'];
            if (!ValueValidator::isList($children)) {
                $errors[] = self::error($base . '.children', 'Must be a list of node ids.');
            } else {
                if ($manifest !== null && !$manifest->acceptsChildren && $children !== []) {
                    $errors[] = self::error($base . '.children', 'A ' . $manifest->type . ' cannot contain children.');
                }
                foreach ($children as $childId) {
                    if (!is_string($childId)) {
                        $errors[] = self::error($base . '.children', 'Node ids must be strings.');
                    } elseif (!array_key_exists($childId, $allNodes)) {
                        $errors[] = self::error($base . '.children', 'Unknown node "' . $childId . '".');
                    }
                }
            }
        }

        if (array_key_exists('props', $node) && $manifest !== null) {
            $this->validateProps($base, $manifest, $node['props'], $errors);
        }

        if (array_key_exists('style', $node)) {
            $this->validateStyle($base, $manifest, $node['style'], $errors);
        }

        if (array_key_exists('advanced', $node)) {
            $this->validateAdvanced($base, $node['advanced'], $errors);
        }

        return $node;
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateProps(string $base, WidgetManifest $manifest, mixed $props, array &$errors): void
    {
        if (!ValueValidator::isObject($props)) {
            $errors[] = self::error($base . '.props', 'Must be an object.');

            return;
        }

        foreach ($props as $key => $value) {
            $path = $base . '.props.' . $key;
            $spec = $manifest->props[(string) $key] ?? null;
            if ($spec === null) {
                $errors[] = self::error($path, 'Unknown prop for a ' . $manifest->type . '.');
                continue;
            }
            if (($message = $this->values->prop($spec, $value)) !== null) {
                $errors[] = self::error($path, $message);
            }
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateStyle(string $base, ?WidgetManifest $manifest, mixed $style, array &$errors): void
    {
        if (!ValueValidator::isObject($style)) {
            $errors[] = self::error($base . '.style', 'Must be an object.');

            return;
        }

        foreach ($style as $key => $responsive) {
            $key = (string) $key;
            $path = $base . '.style.' . $key;
            $spec = $this->schema->styleProperties[$key] ?? null;

            if ($spec === null) {
                $errors[] = self::error($path, 'Unknown style key.');
                continue;
            }
            if ($manifest !== null && !$manifest->allowsStyle($key)) {
                $errors[] = self::error($path, 'This style key is not available on a ' . $manifest->type . '.');
                continue;
            }
            if (!ValueValidator::isObject($responsive)) {
                $errors[] = self::error($path, 'Must be an object keyed by device.');
                continue;
            }

            $wrapperValid = true;
            foreach ($responsive as $device => $_) {
                if (!in_array($device, $this->schema->devices, true)) {
                    $errors[] = self::error($path, 'Unknown device "' . $device . '".');
                    $wrapperValid = false;
                }
            }
            if (!$wrapperValid) {
                continue;
            }

            foreach ($responsive as $device => $value) {
                if ($value !== null && ($message = $this->values->style($spec, $value)) !== null) {
                    $errors[] = self::error($path . '.' . $device, $message);
                }
            }
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateAdvanced(string $base, mixed $advanced, array &$errors): void
    {
        if (!ValueValidator::isObject($advanced)) {
            $errors[] = self::error($base . '.advanced', 'Must be an object.');

            return;
        }

        foreach ($advanced as $key => $value) {
            $message = match ($key) {
                'cssId' => $this->values->cssId($value),
                'cssClasses' => $this->values->cssClasses($value),
                'hideOn' => $this->values->hideOn($value),
                'customCss' => $this->values->customCss($value, $this->schema->maxCustomCssLength),
                default => 'Unknown key.',
            };
            if ($message !== null) {
                $errors[] = self::error($base . '.advanced.' . $key, $message);
            }
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function validateSettings(mixed $settings, array &$errors): void
    {
        if (!ValueValidator::isObject($settings)) {
            $errors[] = self::error('settings', 'Must be an object.');

            return;
        }

        foreach ($settings as $key => $value) {
            $path = 'settings.' . $key;
            $spec = $this->schema->settings[(string) $key] ?? null;
            if ($spec === null) {
                $errors[] = self::error($path, 'Unknown setting.');
                continue;
            }
            if (($message = $this->values->setting($spec, $value)) !== null) {
                $errors[] = self::error($path, $message);
            }
        }
    }

    /**
     * Child ids of a node that look like ids; everything else is reported by {@see validateNode()}.
     *
     * @param array<array-key, mixed> $node
     *
     * @return list<string>
     */
    private static function childIds(array $node): array
    {
        $children = $node['children'] ?? null;
        if (!ValueValidator::isList($children)) {
            return [];
        }

        $ids = [];
        foreach ($children as $child) {
            if (is_string($child)) {
                $ids[] = $child;
            }
        }

        return $ids;
    }

    /**
     * @return ValidationError
     */
    private static function error(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }

    /**
     * @param list<ValidationError> $errors
     *
     * @return list<ValidationError>
     */
    private static function unique(array $errors): array
    {
        $seen = [];
        $unique = [];
        foreach ($errors as $error) {
            $key = $error['path'] . "\0" . $error['message'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $error;
            }
        }

        return $unique;
    }
}
