<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * WordPress-style capabilities. Values are sent to the editor in `/api/session` and must stay
 * stable (README §8).
 */
enum Capability: string
{
    case EditPages = 'edit_pages';
    case EditOthersPages = 'edit_others_pages';
    case PublishPages = 'publish_pages';
    case DeletePages = 'delete_pages';
    case DeleteOthersPages = 'delete_others_pages';
    case UploadFiles = 'upload_files';
    case ManageUsers = 'manage_users';
    /** Edit site-wide global styles (README §9). */
    case EditThemeOptions = 'edit_theme_options';
}
