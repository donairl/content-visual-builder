<?php

declare(strict_types=1);

namespace App\Domain\Page;

use App\Domain\Document\DocumentJson;
use App\Domain\Render\PublicPageRenderer;
use App\Domain\Site\SiteContextFactory;

/**
 * Renders every published page again from its *published* snapshot, against the current site
 * data. Used after site-wide changes (global styles, component publishes) and by `pages:rerender`
 * after a deploy.
 * Drafts are not touched and nothing unpublished goes live.
 */
final readonly class PublishedPagesRenderer
{
    public function __construct(
        private PageRepository $pages,
        private PublicPageRenderer $renderer,
        private SiteContextFactory $site,
    ) {}

    /**
     * @param callable(Page): void|null $onPage Called after each page is stored.
     *
     * @return int Number of pages rendered.
     */
    public function rerenderAll(?callable $onPage = null): int
    {
        $site = $this->site->forAll();
        $count = 0;

        foreach ($this->pages->publishedIds() as $id) {
            $page = $this->pages->findPublishedDocument($id);
            if ($page === null || $page->publishedDocument === null) {
                continue;
            }

            $rendered = $this->renderer->render($page->id, $page->title, DocumentJson::decode($page->publishedDocument), $site);
            $this->pages->updatePublishedRender($page->id, $rendered);
            if ($onPage !== null) {
                $onPage($page);
            }
            $count++;
        }

        return $count;
    }
}
