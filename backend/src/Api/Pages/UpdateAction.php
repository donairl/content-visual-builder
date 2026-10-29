<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Domain\Page\PageForm;
use App\Domain\Page\PageRepository;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

use function array_key_exists;

/**
 * `PATCH /api/pages/{id}` `{ title?, slug? }` → PageSummary.
 *
 * Renaming the slug of a published page moves its public URL at once; the published HTML (with
 * the title baked in) only changes on the next publish.
 */
final readonly class UpdateAction
{
    public function __construct(
        private PageLoader $loader,
        private PageRepository $pages,
        private PageForm $form,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->loader->load($request, Capability::EditPages);
        if ($page instanceof ResponseInterface) {
            return $page;
        }

        $data = RequestBody::fields($request);
        $title = array_key_exists('title', $data) ? $data['title'] : $page->title;
        $slug = array_key_exists('slug', $data) ? $data['slug'] : $page->slug;
        if (is_string($slug)) {
            $slug = PageForm::slugify($slug);
        }

        $errors = $this->form->validate($title, $slug, $page->id);
        if ($errors !== []) {
            return $this->responder->validation($errors);
        }

        /** @var string $title */
        /** @var string $slug */
        $this->pages->updateMeta($page->id, trim($title), $slug);
        $updated = $this->pages->findById($page->id) ?? $page;

        return $this->responder->json(Status::OK, $updated->toSummary());
    }
}
