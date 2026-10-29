<?php

declare(strict_types=1);

namespace App\Domain\Render;

/**
 * Video URL recognition and player markup (README §7).
 *
 * The three regexes are copied verbatim from the contract and tried in order, first match wins, so
 * the editor preview and the published page always pick the same provider. `D` keeps `$` from
 * accepting a trailing newline, which JS's `$` would not.
 */
final class VideoEmbed
{
    public const YOUTUBE = '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/)|youtu\.be/|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})(?:[?&#/].*)?$~iD';
    public const VIMEO = '~^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?([0-9]{1,12})(?:[?#/].*)?$~iD';
    public const FILE = '~^https?://[^?#]+\.(?:mp4|webm|ogv|ogg)(?:[?#].*)?$~iD';

    private const IFRAME_ALLOW = 'autoplay; encrypted-media; picture-in-picture; fullscreen';

    /**
     * @return array{provider: 'youtube'|'vimeo'|'file', id: string}|null `id` is the URL itself for files.
     */
    public static function match(string $url): ?array
    {
        if (preg_match(self::YOUTUBE, $url, $m) === 1) {
            return ['provider' => 'youtube', 'id' => $m[1]];
        }
        if (preg_match(self::VIMEO, $url, $m) === 1) {
            return ['provider' => 'vimeo', 'id' => $m[1]];
        }
        if (preg_match(self::FILE, $url) === 1) {
            return ['provider' => 'file', 'id' => $url];
        }

        return null;
    }

    /**
     * The iframe `src` for a YouTube/Vimeo URL, or `null` for files and unknown URLs.
     */
    public static function embedUrl(string $url, bool $autoplay, bool $muted, bool $loop, bool $controls): ?string
    {
        $match = self::match($url);
        if ($match === null || $match['provider'] === 'file') {
            return null;
        }

        $id = $match['id'];
        $params = [];

        if ($match['provider'] === 'youtube') {
            if ($autoplay) {
                $params[] = 'autoplay=1';
            }
            // Browsers only honour autoplay for muted media, so autoplay implies mute.
            if ($muted || $autoplay) {
                $params[] = 'mute=1';
            }
            if ($loop) {
                // YouTube only loops a single video when it is also its own playlist.
                $params[] = 'loop=1&playlist=' . $id;
            }
            if (!$controls) {
                $params[] = 'controls=0';
            }
            $base = 'https://www.youtube-nocookie.com/embed/' . $id;
        } else {
            if ($autoplay) {
                $params[] = 'autoplay=1';
            }
            if ($muted || $autoplay) {
                $params[] = 'muted=1';
            }
            if ($loop) {
                $params[] = 'loop=1';
            }
            if (!$controls) {
                $params[] = 'controls=0';
            }
            $base = 'https://player.vimeo.com/video/' . $id;
        }

        return $params === [] ? $base : $base . '?' . implode('&', $params);
    }

    /**
     * Player markup for the video widget; empty for an empty or unrecognised URL.
     */
    public static function player(string $url, string $title, bool $autoplay, bool $muted, bool $loop, bool $controls): string
    {
        $match = $url === '' ? null : self::match($url);
        if ($match === null) {
            return '';
        }

        if ($match['provider'] === 'file') {
            return '<video' . Html::attributes([
                'src' => $url,
                'preload' => 'metadata',
                'playsinline' => true,
                'controls' => $controls,
                'autoplay' => $autoplay,
                'muted' => $muted || $autoplay,
                'loop' => $loop,
            ]) . '></video>';
        }

        return '<iframe' . Html::attributes([
            'src' => (string) self::embedUrl($url, $autoplay, $muted, $loop, $controls),
            'title' => $title,
            'loading' => 'lazy',
            'allow' => self::IFRAME_ALLOW,
            'allowfullscreen' => true,
            'referrerpolicy' => 'strict-origin-when-cross-origin',
        ]) . '></iframe>';
    }
}
