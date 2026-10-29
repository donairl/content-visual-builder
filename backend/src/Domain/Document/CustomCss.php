<?php

declare(strict_types=1);

namespace App\Domain\Document;

use function count;
use function in_array;
use function mb_ord;
use function mb_str_split;

/**
 * Structural check for custom CSS (README §2 "Custom CSS"), mirrored by the editor's
 * `core/customCss.ts`. Both must accept and reject exactly the same strings.
 *
 * Custom CSS is emitted inside a wrapper rule (`.cvb-root{…}`, `.cvb-el-{id}{…}`) and native CSS
 * nesting scopes it, so safety comes down to one property: the browser must see the same block
 * structure as this scanner, so nothing inside can close the wrapper. The scanner accepts a subset
 * of CSS in which every bracket the browser could treat as structural is visible and balanced:
 * comments and strings are skipped like the CSS tokenizer does, unquoted url() bodies may not
 * contain brackets or quotes, and escapes (which could hide a bracket) are only allowed in strings.
 */
final class CustomCss
{
    private const OPEN = ['(' => ')', '[' => ']', '{' => '}'];
    private const CLOSE = [')', ']', '}'];
    private const WHITESPACE = [' ', "\t", "\n", "\r", "\f"];
    private const NEWLINES = ["\n", "\r", "\f"];
    private const URL_FORBIDDEN = ['"', "'", '(', '\\', '{', '}', '[', ']'];

    /**
     * `null` when the CSS is acceptable, otherwise why not. Length limits are checked by callers.
     */
    public static function error(string $css): ?string
    {
        $cps = mb_str_split($css, 1, 'UTF-8');
        foreach ($cps as $ch) {
            if ($ch === '<') {
                return 'The character "<" is not allowed.';
            }
            if (self::isControl($ch)) {
                return 'Control characters are not allowed.';
            }
        }

        $n = count($cps);
        /** @var list<string> $stack */
        $stack = [];
        $i = 0;
        while ($i < $n) {
            $ch = $cps[$i];

            if ($ch === '/' && ($cps[$i + 1] ?? null) === '*') {
                $end = -1;
                for ($j = $i + 2; $j + 1 < $n; $j++) {
                    if ($cps[$j] === '*' && $cps[$j + 1] === '/') {
                        $end = $j;
                        break;
                    }
                }
                if ($end === -1) {
                    return 'A comment is not closed.';
                }
                $i = $end + 2;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $j = $i + 1;
                while (true) {
                    if ($j >= $n) {
                        return 'A string is not closed.';
                    }
                    $c = $cps[$j];
                    if ($c === $ch) {
                        break;
                    }
                    if ($c === '\\') {
                        if ($j + 1 >= $n) {
                            return 'A string is not closed.';
                        }
                        $j += 2;
                        continue;
                    }
                    if (in_array($c, self::NEWLINES, true)) {
                        return 'Line breaks inside strings must be escaped.';
                    }
                    $j++;
                }
                $i = $j + 1;
                continue;
            }

            if ($ch === '\\') {
                return 'Backslash escapes are only allowed inside strings.';
            }

            if (self::isUrlStart($cps, $i)) {
                $j = $i + 4;
                while ($j < $n && in_array($cps[$j], self::WHITESPACE, true)) {
                    $j++;
                }
                $next = $cps[$j] ?? null;
                if ($next === '"' || $next === "'") {
                    // url("…") is an ordinary function holding a string.
                    $stack[] = ')';
                    $i = $j;
                    continue;
                }
                while (true) {
                    if ($j >= $n) {
                        return 'A url( is not closed.';
                    }
                    $c = $cps[$j];
                    if ($c === ')') {
                        break;
                    }
                    if (in_array($c, self::WHITESPACE, true)) {
                        $k = $j;
                        while ($k < $n && in_array($cps[$k], self::WHITESPACE, true)) {
                            $k++;
                        }
                        if (($cps[$k] ?? null) !== ')') {
                            return 'Unquoted url() values cannot contain spaces.';
                        }
                        $j = $k;
                        break;
                    }
                    if (in_array($c, self::URL_FORBIDDEN, true)) {
                        return 'Unquoted url() values cannot contain quotes, brackets or backslashes.';
                    }
                    $j++;
                }
                $i = $j + 1;
                continue;
            }

            if (isset(self::OPEN[$ch])) {
                $stack[] = self::OPEN[$ch];
            } elseif (in_array($ch, self::CLOSE, true)) {
                if (array_pop($stack) !== $ch) {
                    return 'Unbalanced "' . $ch . '".';
                }
            }
            $i++;
        }

        return $stack === [] ? null : 'A bracket is not closed.';
    }

    private static function isControl(string $ch): bool
    {
        $code = mb_ord($ch, 'UTF-8');

        return ($code < 0x20 && !in_array($ch, self::WHITESPACE, true)) || $code === 0x7F;
    }

    /**
     * @param list<string> $cps
     */
    private static function isUrlStart(array $cps, int $i): bool
    {
        return ($cps[$i] === 'u' || $cps[$i] === 'U')
            && (($cps[$i + 1] ?? '') === 'r' || ($cps[$i + 1] ?? '') === 'R')
            && (($cps[$i + 2] ?? '') === 'l' || ($cps[$i + 2] ?? '') === 'L')
            && ($cps[$i + 3] ?? '') === '(';
    }
}
