<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Role;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\HttpSession;
use App\Tests\Support\TestDatabase;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFile;

use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;

final class MediaUploadCest
{
    /**
     * A 3×2 PNG.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAEklEQVR4nGP4z8DAwMDAwMAAAA4AAf/HoMN1AAAAAElFTkSuQmCC';

    /**
     * @var list<string>
     */
    private array $stored = [];

    public function _after(): void
    {
        foreach ($this->stored as $file) {
            @unlink($file);
            // Month and year directories go too, but only if the test left them empty.
            @rmdir(dirname($file));
            @rmdir(dirname($file, 2));
        }
        $this->stored = [];
    }

    public function imageIsStoredUnderARandomName(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));

        // The client claims a name and type; neither is trusted.
        $response = $session->request('POST', '/api/media', files: ['file' => self::file(base64_decode(self::PNG), 'photo.php', 'text/plain')]);

        assertSame(201, $response->getStatusCode());
        $media = HttpSession::json($response);
        assertMatchesRegularExpression('~^/uploads/\d{4}/\d{2}/[a-f0-9]{32}\.png$~', (string) $media['url']);
        assertSame(3, $media['width']);
        assertSame(2, $media['height']);
        assertSame('image/png', $media['mime']);

        $path = dirname(__DIR__, 2) . '/public' . $media['url'];
        $this->stored[] = $path;
        assertFileExists($path);
    }

    public function nonImagesAreRejected(FunctionalTester $tester): void
    {
        $session = HttpSession::signedIn($tester, TestDatabase::createUser(Role::Author));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        foreach ([[$svg, 'logo.svg', 'image/svg+xml'], ['<?php echo 1;', 'x.png', 'image/png']] as [$contents, $name, $type]) {
            $response = $session->request('POST', '/api/media', files: ['file' => self::file($contents, $name, $type)]);
            assertSame(422, $response->getStatusCode(), $name);
            assertSame('file', HttpSession::json($response)['errors'][0]['path']);
        }

        assertSame(422, $session->request('POST', '/api/media')->getStatusCode());
    }

    public function guestsCannotUpload(FunctionalTester $tester): void
    {
        $guest = new HttpSession($tester);
        // A guest has a CSRF token too (it lives in the session), so only authentication stops this.
        $form = (string) $guest->request('GET', '/login')->getBody();
        preg_match('~name="_csrf" value="([^"]+)"~', $form, $m);
        $guest->csrfToken = html_entity_decode($m[1] ?? '');

        $response = $guest->request('POST', '/api/media', files: ['file' => self::file(base64_decode(self::PNG), 'a.png', 'image/png')]);

        assertSame(401, $response->getStatusCode());
    }

    private static function file(string $contents, string $name, string $type): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($contents), strlen($contents), UPLOAD_ERR_OK, $name, $type);
    }
}
