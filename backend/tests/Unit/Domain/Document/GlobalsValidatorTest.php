<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Document;

use App\Domain\Document\GlobalsValidator;
use App\Tests\Support\Contract;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertSame;

final class GlobalsValidatorTest extends Unit
{
    /**
     * Every case of `schema/fixtures/validation/globals-cases.json` (README §9), shared with the
     * editor's `validateGlobals()`.
     */
    #[DataProvider('cases')]
    public function testSharedGlobalsCase(bool $valid, ?string $errorPath, mixed $globals): void
    {
        $errors = (new GlobalsValidator(Contract::schema()))->validate($globals);
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
        $cases = Contract::json('fixtures/validation/globals-cases.json');
        foreach (is_array($cases) ? $cases : [] as $case) {
            yield (string) $case['name'] => [(bool) $case['valid'], $case['errorPath'] ?? null, $case['globals']];
        }
    }

    public function testDefaultGlobalsAreValid(): void
    {
        assertSame([], (new GlobalsValidator(Contract::schema()))->validate(Contract::schema()->defaultGlobals));
    }
}
