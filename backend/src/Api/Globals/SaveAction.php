<?php

declare(strict_types=1);

namespace App\Api\Globals;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Api\Shared\RequireApiUser;
use App\Domain\Document\DocumentJson;
use App\Domain\Document\GlobalsValidator;
use App\Domain\Page\PublishedPagesRenderer;
use App\Domain\Shared\Clock;
use App\Domain\Site\GlobalStyles;
use App\Domain\Site\SiteSettingsRepository;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

use function array_key_exists;
use function is_int;

/**
 * `PUT /api/globals` `{ globals, baseVersion }` → `{ version, updatedAt, rerendered }` (README §9).
 *
 * Global styles are live site-wide: after a successful compare-and-swap save every published page
 * is rendered again, so the public site matches what the editor shows. `baseVersion` 0 is the
 * first save over the schema defaults.
 */
final readonly class SaveAction
{
    public function __construct(
        private GlobalStyles $globals,
        private SiteSettingsRepository $settings,
        private GlobalsValidator $validator,
        private PublishedPagesRenderer $published,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequireApiUser::user($request);
        if (!Gate::can($user, Capability::EditThemeOptions)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to change the global styles.');
        }

        $data = RequestBody::fields($request);
        $baseVersion = $data['baseVersion'] ?? null;
        if (!is_int($baseVersion) || $baseVersion < 0) {
            return $this->responder->validation([['path' => 'baseVersion', 'message' => 'baseVersion must be a non-negative integer.']]);
        }
        if (!array_key_exists('globals', $data)) {
            return $this->responder->validation([['path' => 'globals', 'message' => 'The globals are required.']]);
        }

        $current = $this->globals->current();
        if ($baseVersion !== $current->version) {
            return $this->conflict($current->version, $current->updatedAt);
        }

        $errors = $this->validator->validate($data['globals']);
        if ($errors !== []) {
            return $this->responder->validation($errors, 'The global styles are invalid.');
        }

        $saved = $this->settings->save(GlobalStyles::SETTING, DocumentJson::encode($data['globals'], DocumentJson::GLOBALS_LIST_KEYS), $baseVersion, $user->id);
        if ($saved === null) {
            $latest = $this->globals->current();

            return $this->conflict($latest->version, $latest->updatedAt);
        }

        return $this->responder->json(Status::OK, [
            'version' => $saved->version,
            'updatedAt' => Clock::toApi($saved->updatedAt),
            'rerendered' => $this->published->rerenderAll(),
        ]);
    }

    private function conflict(int $version, ?\DateTimeImmutable $updatedAt): ResponseInterface
    {
        return $this->responder->error(Status::CONFLICT, 'The global styles were changed by someone else. Reload them to continue.', [
            'currentVersion' => $version,
            'updatedAt' => Clock::toApi($updatedAt),
        ]);
    }
}
