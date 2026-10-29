<?php

declare(strict_types=1);

namespace App\Domain\Page;

use Yiisoft\Validator\Result;
use Yiisoft\Validator\Rule\Callback;
use Yiisoft\Validator\Rule\Length;
use Yiisoft\Validator\Rule\Regex;
use Yiisoft\Validator\Rule\Required;
use Yiisoft\Validator\ValidatorInterface;

use function in_array;
use function is_string;

/**
 * Validates page metadata (title, slug) on create and on update.
 *
 * @psalm-type FieldError = array{path: string, message: string}
 */
final readonly class PageForm
{
    /**
     * First path segments owned by routes; a page there would be unreachable or would shadow them.
     */
    public const RESERVED_SLUGS = ['api', 'assets', 'editor', 'login', 'logout', 'preview', 'uploads'];

    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const TITLE_MAX_LENGTH = 200;
    public const SLUG_MAX_LENGTH = 100;

    public function __construct(
        private ValidatorInterface $validator,
        private PageRepository $pages,
    ) {}

    /**
     * Lowercase ASCII words joined by single hyphens: "Über uns!" → "uber-uns".
     */
    public static function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\pN]+~u', '-', $text) ?? '';
        $text = strtolower(self::toAscii($text));
        $text = preg_replace('~[^a-z0-9-]+~', '', $text) ?? '';
        $text = preg_replace('~-+~', '-', $text) ?? '';

        return substr(trim($text, '-'), 0, self::SLUG_MAX_LENGTH);
    }

    /**
     * ICU when available (same result everywhere); iconv's transliteration is the fallback, but it
     * differs between glibc and musl.
     */
    private static function toAscii(string $text): string
    {
        if (class_exists(\Transliterator::class)) {
            $ascii = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($text);
            if (is_string($ascii)) {
                return $ascii;
            }
        }

        $ascii = iconv('UTF-8', 'US-ASCII//TRANSLIT//IGNORE', $text);

        return $ascii === false ? '' : $ascii;
    }

    /**
     * A free slug derived from `$base`: `about`, then `about-2`, `about-3`, … Used when the client
     * did not choose one, so that creating "About" twice just works.
     */
    public function availableSlug(string $base, ?int $exceptId = null): string
    {
        $base = self::slugify($base);
        if ($base === '') {
            $base = 'page';
        }

        $candidate = $base;
        for ($n = 2; self::isReserved($candidate) || $this->pages->slugExists($candidate, $exceptId); $n++) {
            $suffix = '-' . $n;
            $candidate = rtrim(substr($base, 0, self::SLUG_MAX_LENGTH - strlen($suffix)), '-') . $suffix;
        }

        return $candidate;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED_SLUGS, true);
    }

    /**
     * @param int|null $exceptId The page being updated, which may keep its own slug.
     *
     * @return list<FieldError>
     */
    public function validate(mixed $title, mixed $slug, ?int $exceptId = null): array
    {
        $rules = [
            'title' => [
                new Required(message: 'Title is required.'),
                new Length(max: self::TITLE_MAX_LENGTH, greaterThanMaxMessage: 'Title cannot exceed ' . self::TITLE_MAX_LENGTH . ' characters.'),
            ],
            'slug' => [
                new Required(message: 'Slug is required.'),
                new Length(max: self::SLUG_MAX_LENGTH, greaterThanMaxMessage: 'Slug cannot exceed ' . self::SLUG_MAX_LENGTH . ' characters.'),
                new Regex(self::SLUG_PATTERN, message: 'Slug may only contain lowercase letters, digits and single hyphens.'),
                new Callback(
                    callback: function (mixed $value) use ($exceptId): Result {
                        $result = new Result();
                        $slug = is_string($value) ? $value : '';
                        if (self::isReserved($slug)) {
                            $result->addError('This slug is reserved.');
                        } elseif ($slug !== '' && $this->pages->slugExists($slug, $exceptId)) {
                            $result->addError('Another page already uses this slug.');
                        }

                        return $result;
                    },
                    skipOnError: true,
                ),
            ],
        ];

        $result = $this->validator->validate([
            'title' => is_string($title) ? trim($title) : $title,
            'slug' => $slug,
        ], $rules);

        $errors = [];
        foreach ($result->getFirstErrorMessagesIndexedByPath() as $path => $message) {
            $errors[] = ['path' => $path, 'message' => $message];
        }

        return $errors;
    }
}
