<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Document\Schema;
use App\Domain\Render\SiteContext;

/**
 * The site's global styles (README §9): the saved value, or the schema default (`globals.json`)
 * until someone saves them.
 *
 * @psalm-suppress ClassMustBeFinal Deliberately extensible so it can be doubled in tests.
 */
class GlobalStyles
{
    public const string SETTING = 'globals';

    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly Schema $schema,
    ) {}

    public function current(): StoredSetting
    {
        return $this->settings->find(self::SETTING) ?? new StoredSetting($this->schema->defaultGlobals, 0, null);
    }

    /**
     * What pages render against right now.
     */
    public function siteContext(): SiteContext
    {
        return SiteContext::fromGlobals($this->schema, $this->current()->value);
    }
}
