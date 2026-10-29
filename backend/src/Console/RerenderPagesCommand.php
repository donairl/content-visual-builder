<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Page\Page;
use App\Domain\Page\PublishedPagesRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

/**
 * `./yii pages:rerender`: renders every published page again from its *published* snapshot.
 *
 * Run after deploying a renderer change or a new `schema/base.css` (whose hash is in every stored
 * page). Drafts are not touched and nothing unpublished goes live.
 */
#[AsCommand(
    name: 'pages:rerender',
    description: 'Re-renders all published pages from their published documents',
)]
final class RerenderPagesCommand extends Command
{
    public function __construct(
        private readonly PublishedPagesRenderer $renderer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = $this->renderer->rerenderAll(
            static fn(Page $page) => $io->writeln(sprintf('Rendered #%d /%s', $page->id, $page->slug), OutputInterface::VERBOSITY_VERBOSE),
        );

        $io->success(sprintf('Re-rendered %d published page(s).', $count));

        return ExitCode::OK;
    }
}
