<?php

declare(strict_types=1);

return [
    'yiisoft/yii-console' => [
        'commands' => require __DIR__ . '/commands.php',
    ],

    /**
     * yiisoft/db-migration registers `migrate:*` itself but ships empty source settings; without
     * these it refuses to run. Migrations live in `src/Migration` (PSR-4 `App\Migration`).
     */
    'yiisoft/db-migration' => [
        'newMigrationNamespace' => 'App\\Migration',
        'sourceNamespaces' => ['App\\Migration'],
    ],
];
