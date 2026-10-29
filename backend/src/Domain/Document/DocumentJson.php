<?php

declare(strict_types=1);

namespace App\Domain\Document;

use JsonException;
use stdClass;

use function in_array;
use function is_array;
use function strlen;

/**
 * JSON (de)serialisation of page documents.
 *
 * Documents are handled as PHP arrays, where `{}` and `[]` are indistinguishable once decoded. The
 * contract has exactly three list-valued keys — `rootIds`, `children` and `hideOn` — so on the way
 * out every other array is forced to a JSON object. Without that an empty `nodes`, `props` or
 * `style` would be stored and served as `[]`, which the editor's validator rightly rejects.
 */
final class DocumentJson
{
    /**
     * Matches `JSON.stringify` output closely enough for byte counting: no escaped slashes or
     * non-ASCII characters.
     */
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const LIST_KEYS = ['rootIds', 'children', 'hideOn'];

    /**
     * Global styles (README §9) have their own list-valued keys.
     */
    public const GLOBALS_LIST_KEYS = ['colors', 'fonts'];

    /**
     * @param list<string> $listKeys Keys whose list values stay JSON arrays.
     *
     * @throws JsonException
     */
    public static function encode(mixed $document, array $listKeys = self::LIST_KEYS): string
    {
        return json_encode(self::toJsonValue($document, null, $listKeys), self::FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * Decodes stored JSON for the renderers (arrays) — never throws, a broken row renders as empty.
     */
    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Decodes stored JSON keeping objects as objects, for embedding verbatim in an API response.
     */
    public static function decodeForResponse(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new stdClass();
        }
    }

    /**
     * Size in bytes of the document's JSON encoding, compared against `limits.maxDocumentBytes`.
     */
    public static function byteSize(mixed $document): int
    {
        try {
            return strlen(self::encode($document));
        } catch (JsonException) {
            return PHP_INT_MAX;
        }
    }

    /**
     * The value with every array that is not under a list key turned into an object.
     *
     * @param list<string> $listKeys
     */
    public static function toJsonValue(mixed $value, ?string $key = null, array $listKeys = self::LIST_KEYS): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($key !== null && in_array($key, $listKeys, true) && array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::toJsonValue($item, null, $listKeys), $value);
        }

        $converted = [];
        foreach ($value as $itemKey => $item) {
            $converted[$itemKey] = self::toJsonValue($item, (string) $itemKey, $listKeys);
        }

        // A cast rather than property writes: it also copes with keys such as "" that cannot be
        // written as a property name.
        return (object) $converted;
    }
}
