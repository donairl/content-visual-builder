<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Document;

use App\Domain\Document\DocumentValidator;
use App\Tests\Support\Contract;
use Codeception\Test\Unit;
use Codeception\Attribute\DataProvider;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

final class DocumentValidatorTest extends Unit
{
    /**
     * Every case of `schema/fixtures/validation/cases.json`: a valid case yields no error at all, an
     * invalid one must report its `errorPath` (other errors may accompany it).
     */
    #[DataProvider('cases')]
    public function testSharedValidationCase(bool $valid, ?string $errorPath, mixed $document): void
    {
        $errors = (new DocumentValidator(Contract::schema()))->validate($document);
        $paths = array_column($errors, 'path');

        if ($valid) {
            assertSame([], $errors);
        } else {
            assertContains($errorPath, $paths, 'Reported: ' . json_encode($paths));
        }
    }

    /**
     * @return iterable<string, array{bool, ?string, mixed}>
     */
    public static function cases(): iterable
    {
        $cases = Contract::json('fixtures/validation/cases.json');
        foreach (is_array($cases) ? $cases : [] as $case) {
            yield (string) $case['name'] => [(bool) $case['valid'], $case['errorPath'] ?? null, $case['document']];
        }
    }

    public function testCaseFileHasTheAgreedCount(): void
    {
        assertSame(89, iterator_count(self::cases()));
    }

    #[DataProvider('parityDocuments')]
    public function testParityDocumentsAreValid(string $name): void
    {
        $document = Contract::json('fixtures/documents/' . $name . '.json');

        assertSame([], (new DocumentValidator(Contract::schema()))->validate($document));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function parityDocuments(): iterable
    {
        yield 'landing' => ['landing'];
        yield 'styles' => ['styles'];
        yield 'edge' => ['edge'];
        yield 'custom-css' => ['custom-css'];
        yield 'globals' => ['globals'];
        yield 'components' => ['components'];
    }

    public function testOversizedDocumentReportsOnlyTheSizeLimit(): void
    {
        $document = Contract::json('fixtures/documents/styles.json');

        $errors = (new DocumentValidator(Contract::schema()))->validate($document, Contract::schema()->maxDocumentBytes + 1);

        assertSame(['document'], array_column($errors, 'path'));
    }

    public function testNonObjectDocument(): void
    {
        assertSame(['document'], array_column((new DocumentValidator(Contract::schema()))->validate(['a', 'b']), 'path'));
        assertSame(['document'], array_column((new DocumentValidator(Contract::schema()))->validate('x'), 'path'));
    }

    public function testStringLengthsCountCodePoints(): void
    {
        $document = Contract::json('fixtures/documents/styles.json');
        // 200 four-byte emoji: 200 code points (allowed), 800 bytes, 400 UTF-16 units.
        $document['nodes']['btn00002']['props']['text'] = str_repeat("\u{1F600}", 200);
        assertSame([], (new DocumentValidator(Contract::schema()))->validate($document));

        $document['nodes']['btn00002']['props']['text'] .= 'x';
        assertSame(['nodes.btn00002.props.text'], array_column((new DocumentValidator(Contract::schema()))->validate($document), 'path'));
    }

    public function testNumbersMustBeJsonNumbers(): void
    {
        $document = Contract::json('fixtures/documents/styles.json');
        $document['nodes']['wrap0001']['style']['zIndex'] = ['desktop' => '5'];
        $document['nodes']['wrap0001']['style']['opacity'] = ['desktop' => true];
        $document['settings']['contentWidth'] = 960.0;

        $paths = array_column((new DocumentValidator(Contract::schema()))->validate($document), 'path');

        // Reported in the document's key order (opacity precedes zIndex in the fixture's style object).
        sort($paths);
        assertSame(['nodes.wrap0001.style.opacity.desktop', 'nodes.wrap0001.style.zIndex.desktop'], $paths);
    }

    public function testFloatingPointNoiseIsNotADecimal(): void
    {
        $document = Contract::json('fixtures/documents/styles.json');
        $document['nodes']['text0002']['style']['lineHeight'] = ['desktop' => 0.1 + 0.2];

        assertSame([], (new DocumentValidator(Contract::schema()))->validate($document));
    }

    public function testTrailingNewlineDoesNotSlipThroughPatterns(): void
    {
        $document = Contract::json('fixtures/documents/styles.json');
        $document['nodes']['btn00002']['props']['link'] = ['url' => "https://example.com\n", 'newTab' => false, 'nofollow' => false];

        assertSame(['nodes.btn00002.props.link'], array_column((new DocumentValidator(Contract::schema()))->validate($document), 'path'));
    }

    public function testBackgroundOptionalKeysMayBeNullOrEmptyUrl(): void
    {
        $document = Contract::json('fixtures/documents/styles.json');
        $document['nodes']['wrap0001']['style']['background'] = ['desktop' => [
            'type' => 'image', 'color' => null, 'imageUrl' => '', 'imageSize' => null, 'gradientAngle' => null,
        ]];

        assertSame([], (new DocumentValidator(Contract::schema()))->validate($document));

        $document['nodes']['wrap0001']['style']['background'] = ['desktop' => ['color' => '#fff']];
        assertNotSame([], (new DocumentValidator(Contract::schema()))->validate($document));
    }

    public function testSettingsAndAdvancedKeysAreOptional(): void
    {
        $document = Contract::json('fixtures/documents/styles.json');
        $document['settings'] = [];
        $document['nodes']['btn00002']['advanced'] = [];

        assertSame([], (new DocumentValidator(Contract::schema()))->validate($document));
    }

    public function testTooManyNodes(): void
    {
        $schema = Contract::schema();
        $document = ['schemaVersion' => 1, 'rootIds' => [], 'nodes' => [], 'settings' => []];
        for ($i = 0; $i <= $schema->maxNodes; $i++) {
            $id = sprintf('n%07d', $i);
            $document['rootIds'][] = $id;
            $document['nodes'][$id] = ['id' => $id, 'type' => 'spacer', 'props' => [], 'style' => [], 'advanced' => [], 'children' => []];
        }

        assertSame(['nodes'], array_column((new DocumentValidator($schema))->validate($document), 'path'));
    }
}
