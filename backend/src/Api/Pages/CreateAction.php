<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Api\Shared\RequireApiUser;
use App\Domain\Document\BlankDocument;
use App\Domain\Document\DocumentJson;
use App\Domain\Document\Schema;
use App\Domain\Page\PageForm;
use App\Domain\Page\PageRepository;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `POST /api/pages` `{ title, slug? }` → 201 PageDetail.
 *
 * A given slug is normalised (lowercased, hyphenated) and must then be free; without one, a free
 * slug is derived from the title (`about`, `about-2`, …).
 */
final readonly class CreateAction
{
    public function __construct(
        private PageRepository $pages,
        private PageForm $form,
        private Schema $schema,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequireApiUser::user($request);
        if (!Gate::can($user, Capability::EditPages)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to create pages.');
        }

        $data = RequestBody::fields($request);
        $title = $data['title'] ?? null;
        $rawSlug = $data['slug'] ?? null;

        if ($rawSlug === null || $rawSlug === '') {
            $slug = $this->form->availableSlug(is_string($title) ? $title : '');
        } else {
            $slug = is_string($rawSlug) ? PageForm::slugify($rawSlug) : $rawSlug;
        }

        $errors = $this->form->validate($title, $slug);
        if ($errors !== []) {
            return $this->responder->validation($errors);
        }

        $page = $this->pages->create(
            trim((string) $title),
            (string) $slug,
            $user->id,
            DocumentJson::encode(BlankDocument::create($this->schema)),
        );

        return $this->responder->json(Status::CREATED, PageDetail::of($page));
    }
}
