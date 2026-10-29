<?php

declare(strict_types=1);

/**
 * The shared contract (`schema/`) and the services built on it. The schema is parsed once per
 * process; everything else is stateless.
 */

use App\Domain\Document\Schema;
use Yiisoft\Aliases\Aliases;

return [
    Schema::class => static fn(Aliases $aliases): Schema => Schema::fromDirectory($aliases->get('@schema')),
];
