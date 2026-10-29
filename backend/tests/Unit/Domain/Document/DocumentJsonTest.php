<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Document;

use App\Domain\Document\DocumentJson;
use App\Tests\Support\Contract;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

final class DocumentJsonTest extends Unit
{
    /**
     * `{}` and `[]` both decode to `[]`; only the contract's three list keys may encode as arrays.
     */
    public function testEmptyObjectsStayObjects(): void
    {
        $document = [
            'schemaVersion' => 1,
            'rootIds' => [],
            'nodes' => [
                'abcd1234' => ['id' => 'abcd1234', 'type' => 'spacer', 'props' => [], 'style' => [], 'advanced' => ['hideOn' => []], 'children' => []],
            ],
            'settings' => [],
        ];

        assertSame(
            '{"schemaVersion":1,"rootIds":[],"nodes":{"abcd1234":{"id":"abcd1234","type":"spacer","props":{},"style":{},"advanced":{"hideOn":[]},"children":[]}},"settings":{}}',
            DocumentJson::encode($document),
        );
        assertSame('{"schemaVersion":1,"rootIds":[],"nodes":{},"settings":{}}', DocumentJson::encode(['schemaVersion' => 1, 'rootIds' => [], 'nodes' => [], 'settings' => []]));
    }

    public function testFixturesRoundTrip(): void
    {
        foreach (['landing', 'styles', 'edge'] as $name) {
            $raw = Contract::read('fixtures/documents/' . $name . '.json');
            $document = DocumentJson::decode($raw);

            assertSame(
                json_encode(json_decode($raw), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                DocumentJson::encode($document),
                $name,
            );
        }
    }

    public function testNumericNodeIdsSurvive(): void
    {
        $document = DocumentJson::decode('{"nodes":{"12345678":{"id":"12345678"}}}');

        assertSame('{"nodes":{"12345678":{"id":"12345678"}}}', DocumentJson::encode($document));
    }
}
