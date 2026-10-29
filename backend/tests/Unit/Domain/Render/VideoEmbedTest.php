<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Render;

use App\Domain\Render\VideoEmbed;
use Codeception\Test\Unit;
use Codeception\Attribute\DataProvider;

use function PHPUnit\Framework\assertSame;

final class VideoEmbedTest extends Unit
{
    #[DataProvider('urls')]
    public function testMatch(string $url, ?string $provider, ?string $id): void
    {
        $match = VideoEmbed::match($url);

        assertSame($provider, $match['provider'] ?? null);
        assertSame($id, $match['id'] ?? null);
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function urls(): iterable
    {
        yield 'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'youtube', 'dQw4w9WgXcQ'];
        yield 'watch with v later' => ['https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'mobile' => ['http://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'youtube', 'dQw4w9WgXcQ'];
        yield 'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'nocookie' => ['https://youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'uppercase host' => ['HTTPS://WWW.YOUTUBE.COM/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ'];
        yield 'id too short' => ['https://youtu.be/dQw4w9WgXc', null, null];
        yield 'id too long' => ['https://youtu.be/dQw4w9WgXcQQ', null, null];
        yield 'fragment before v' => ['https://www.youtube.com/watch?x=1#&v=dQw4w9WgXcQ', null, null];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'vimeo', '76979871'];
        yield 'vimeo player' => ['https://player.vimeo.com/video/76979871?h=x', 'vimeo', '76979871'];
        yield 'vimeo channel' => ['https://vimeo.com/channels/staffpicks/76979871', null, null];
        yield 'mp4' => ['https://cdn.example.com/clip.MP4?v=2', 'file', 'https://cdn.example.com/clip.MP4?v=2'];
        yield 'webm' => ['http://example.com/a/b.webm#t=3', 'file', 'http://example.com/a/b.webm#t=3'];
        yield 'extension in query only' => ['https://example.com/watch?file=a.mp4', null, null];
        yield 'not a video' => ['https://example.com/watch', null, null];
        yield 'trailing newline' => ["https://vimeo.com/76979871\n", null, null];
    }

    public function testYoutubeParametersInContractOrder(): void
    {
        assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&mute=1&loop=1&playlist=dQw4w9WgXcQ&controls=0',
            VideoEmbed::embedUrl('https://youtu.be/dQw4w9WgXcQ', true, false, true, false),
        );
        assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            VideoEmbed::embedUrl('https://youtu.be/dQw4w9WgXcQ', false, false, false, true),
        );
        assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?mute=1',
            VideoEmbed::embedUrl('https://youtu.be/dQw4w9WgXcQ', false, true, false, true),
        );
    }

    public function testVimeoParametersInContractOrder(): void
    {
        assertSame(
            'https://player.vimeo.com/video/76979871?autoplay=1&muted=1&loop=1&controls=0',
            VideoEmbed::embedUrl('https://vimeo.com/76979871', true, true, true, false),
        );
        assertSame(null, VideoEmbed::embedUrl('https://cdn.example.com/a.mp4', true, true, true, true));
    }

    public function testFilePlayerForcesMuteOnAutoplay(): void
    {
        assertSame(
            '<video src="https://cdn.example.com/a.mp4" preload="metadata" playsinline autoplay muted></video>',
            VideoEmbed::player('https://cdn.example.com/a.mp4', 'T', true, false, false, false),
        );
        assertSame('', VideoEmbed::player('', 'T', false, false, false, true));
        assertSame('', VideoEmbed::player('https://example.com/watch', 'T', false, false, false, true));
    }

    public function testIframeEscapesTitle(): void
    {
        assertSame(
            '<iframe src="https://player.vimeo.com/video/1" title="&quot;Tour&quot; &lt;1&gt;" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>',
            VideoEmbed::player('https://vimeo.com/1', '"Tour" <1>', false, false, false, true),
        );
    }
}
