<?php

declare(strict_types=1);

namespace App\Domain\Page;

enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
