<?php

declare(strict_types=1);

namespace App\Domain\Render;

use App\Domain\Document\Schema;
use App\Domain\Document\ValueValidator;
use App\Domain\Document\WidgetManifest;

use function is_string;

/**
 * The renderable part of a document: an acyclic tree in depth-first pre-order.
 *
 * Built once and shared by the HTML renderer and the CSS generator so both skip exactly the same
 * nodes when handed a document that was never validated: bad ids, unknown types, nodes nested
 * deeper than `limits.maxDepth`, and any second occurrence of an id (shared child or cycle — the
 * first occurrence in pre-order wins). For a valid document nothing is skipped.
 *
 * @psalm-type TreeNode = array{
 *     node: array<array-key, mixed>,
 *     manifest: WidgetManifest,
 *     children: list<string>
 * }
 */
final readonly class RenderTree
{
    /**
     * @param list<string> $roots
     * @param list<string> $order Every included node id, depth-first pre-order.
     * @param array<string, TreeNode> $nodes
     * @param array<array-key, mixed> $settings
     */
    private function __construct(
        public array $roots,
        public array $order,
        public array $nodes,
        public array $settings,
    ) {}

    public static function build(Schema $schema, mixed $document): self
    {
        $document = ValueValidator::asObject($document);
        $rawNodes = ValueValidator::asObject($document['nodes'] ?? null);
        $rawRoots = ValueValidator::asList($document['rootIds'] ?? null);
        $settings = ValueValidator::asObject($document['settings'] ?? null);

        $state = ['visited' => [], 'order' => [], 'nodes' => []];
        $roots = [];
        foreach ($rawRoots as $rootId) {
            if (self::include($schema, $rawNodes, $rootId, 1, $state)) {
                /** @var string $rootId */
                $roots[] = $rootId;
            }
        }

        return new self($roots, $state['order'], $state['nodes'], $settings);
    }

    /**
     * @param array<array-key, mixed> $rawNodes
     * @param array{visited: array<string, true>, order: list<string>, nodes: array<string, TreeNode>} $state
     */
    private static function include(Schema $schema, array $rawNodes, mixed $id, int $depth, array &$state): bool
    {
        if (!is_string($id) || isset($state['visited'][$id]) || $depth > $schema->maxDepth || !$schema->matches('nodeId', $id)) {
            return false;
        }

        $node = ValueValidator::asObject($rawNodes[$id] ?? null);
        $type = $node['type'] ?? null;
        $manifest = is_string($type) ? $schema->widget($type) : null;
        if ($manifest === null) {
            return false;
        }

        $state['visited'][$id] = true;
        $state['order'][] = $id;

        $children = [];
        $rawChildren = $node['children'] ?? null;
        if ($manifest->acceptsChildren && ValueValidator::isList($rawChildren)) {
            foreach ($rawChildren as $childId) {
                if (self::include($schema, $rawNodes, $childId, $depth + 1, $state)) {
                    /** @var string $childId */
                    $children[] = $childId;
                }
            }
        }

        $state['nodes'][$id] = ['node' => $node, 'manifest' => $manifest, 'children' => $children];

        return true;
    }
}
