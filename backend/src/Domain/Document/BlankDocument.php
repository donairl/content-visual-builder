<?php

declare(strict_types=1);

namespace App\Domain\Document;

/**
 * The draft a new page starts with: no nodes, every setting at its `config.json` default.
 */
final class BlankDocument
{
    /**
     * @return array{schemaVersion: int, rootIds: list<string>, nodes: array<string, mixed>, settings: array<string, mixed>}
     */
    public static function create(Schema $schema): array
    {
        $settings = [];
        foreach ($schema->settings as $name => $spec) {
            $settings[$name] = $spec->default;
        }

        return [
            'schemaVersion' => $schema->schemaVersion,
            'rootIds' => [],
            'nodes' => [],
            'settings' => $settings,
        ];
    }
}
