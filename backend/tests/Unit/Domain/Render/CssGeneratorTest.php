<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Render;

use App\Domain\Render\CssGenerator;
use App\Tests\Support\Contract;
use Codeception\Test\Unit;
use Codeception\Attribute\DataProvider;

use function PHPUnit\Framework\assertSame;

final class CssGeneratorTest extends Unit
{
    #[DataProvider('documents')]
    public function testPublishCssIsByteIdenticalToFixture(string $name): void
    {
        $css = (new CssGenerator(Contract::schema()))->generate(Contract::json('fixtures/documents/' . $name . '.json'), Contract::site($name));

        assertSame(Contract::read('fixtures/documents/' . $name . '.expected.css'), $css);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'landing' => ['landing'];
        yield 'styles' => ['styles'];
        yield 'edge' => ['edge'];
        yield 'custom-css' => ['custom-css'];
        yield 'globals' => ['globals'];
        yield 'components' => ['components'];
    }

    #[DataProvider('numbers')]
    public function testFmt(int|float $value, string $expected): void
    {
        assertSame($expected, CssGenerator::fmt($value));
    }

    /**
     * @return iterable<array{int|float, string}>
     */
    public static function numbers(): iterable
    {
        yield [10, '10'];
        yield [-5, '-5'];
        yield [0, '0'];
        yield [10.0, '10'];
        yield [-0.0, '0'];
        yield [1.5, '1.5'];
        yield [0.125, '0.125'];
        yield [-0.5, '-0.5'];
        yield [24.125, '24.125'];
        yield [1.1, '1.1'];
        yield [0.1 + 0.2, '0.3'];
        yield [2.0000000001, '2'];
    }

    /**
     * The generator must be safe on its own: invalid values are skipped, never printed.
     */
    public function testInvalidValuesAreSkipped(): void
    {
        $document = Contract::json('fixtures/documents/edge.json');
        $document['nodes']['head0003']['style'] = [
            'color' => ['desktop' => 'red;}body{display:none', 'mobile' => '#abc'],
            'fontSize' => ['desktop' => ['value' => 12, 'unit' => 'px;x']],
            'hoverBackgroundColor' => ['desktop' => '#000'],
            'float' => ['desktop' => 'left'],
        ];
        $document['nodes']['head0003']['advanced'] = ['cssId' => '', 'cssClasses' => '', 'hideOn' => ['desktop', 'desktop']];

        $css = (new CssGenerator(Contract::schema()))->generate($document);

        assertSame(".cvb-root{--cvb-content-width:1140px}\n@media (max-width:767px){\n.cvb-el-head0003{color:#abc}\n}", $css);
    }

    public function testEmptyDeclarationsCreateNoRule(): void
    {
        $document = Contract::json('fixtures/documents/edge.json');
        $document['nodes']['edge0001']['style'] = [
            'background' => ['desktop' => ['type' => 'gradient', 'gradientFrom' => '#fff']],
            'padding' => ['desktop' => ['unit' => 'px']],
        ];

        assertSame('.cvb-root{--cvb-content-width:1140px}', (new CssGenerator(Contract::schema()))->generate($document));
    }
}
