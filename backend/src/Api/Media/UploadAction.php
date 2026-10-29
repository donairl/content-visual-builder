<?php

declare(strict_types=1);

namespace App\Api\Media;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequireApiUser;
use App\Domain\Media\MediaRepository;
use App\Domain\Shared\Clock;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use finfo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Http\Status;

/**
 * `POST /api/media` (multipart, field `file`) → 201 `{ id, url, width, height, mime, size }`.
 *
 * Only raster images are accepted, and nothing about the upload is trusted:
 *
 * - the type comes from the file's bytes (finfo), not from the client's `Content-Type` or name;
 * - SVG is never accepted — it is a script-capable document, not an image;
 * - the extension is derived from the detected type and the name is random, so the stored file can
 *   neither be executed by the web server nor overwrite another upload;
 * - `getimagesize()` must parse it, which also yields the dimensions the image widget needs.
 */
final readonly class UploadAction
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Detected MIME type → stored extension.
     */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private MediaRepository $media,
        private Aliases $aliases,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequireApiUser::user($request);
        if (!Gate::can($user, Capability::UploadFiles)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to upload files.');
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return $this->invalid('Send the image as the multipart field "file".');
        }

        $error = $file->getError();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return $this->tooLarge();
        }
        if ($error !== UPLOAD_ERR_OK) {
            return $this->invalid('The upload failed.');
        }

        // Reject on the reported size before reading anything into memory.
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            return $this->tooLarge();
        }

        $contents = (string) $file->getStream();
        $size = strlen($contents);
        if ($size > self::MAX_BYTES) {
            return $this->tooLarge();
        }
        if ($size === 0) {
            return $this->invalid('The file is empty.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $extension = is_string($mime) ? (self::TYPES[$mime] ?? null) : null;
        if ($extension === null || !is_string($mime)) {
            return $this->invalid('Only JPEG, PNG, WebP and GIF images are accepted.');
        }

        $info = @getimagesizefromstring($contents);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return $this->invalid('The image could not be read.');
        }

        $now = Clock::now();
        $relativeDir = 'uploads/' . $now->format('Y') . '/' . $now->format('m');
        $directory = $this->aliases->get('@public') . '/' . $relativeDir;
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->responder->error(Status::INTERNAL_SERVER_ERROR, 'The upload directory is not writable.');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        try {
            $file->moveTo($directory . '/' . $name);
        } catch (Throwable) {
            return $this->responder->error(Status::INTERNAL_SERVER_ERROR, 'The upload could not be stored.');
        }

        $url = '/' . $relativeDir . '/' . $name;
        $id = $this->media->create($user->id, $url, $mime, $size, $info[0], $info[1]);

        return $this->responder->json(Status::CREATED, [
            'id' => $id,
            'url' => $url,
            'width' => $info[0],
            'height' => $info[1],
            'mime' => $mime,
            'size' => $size,
        ]);
    }

    private function invalid(string $message): ResponseInterface
    {
        return $this->responder->validation([['path' => 'file', 'message' => $message]], $message);
    }

    private function tooLarge(): ResponseInterface
    {
        return $this->responder->error(Status::PAYLOAD_TOO_LARGE, 'Images may be at most 10 MB.');
    }
}
