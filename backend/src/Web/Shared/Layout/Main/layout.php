<?php

declare(strict_types=1);

use Yiisoft\Html\Html;

/**
 * Layout for the few server-rendered screens (login, 404). Self-contained on purpose: no asset
 * bundle, no script — the editor is a separate static app.
 *
 * @var \App\Shared\ApplicationParams $applicationParams
 * @var string $content
 * @var Yiisoft\View\WebView $this
 */

$this->beginPage();
?>
<!DOCTYPE html>
<html lang="<?= Html::encode($applicationParams->locale) ?>">
<head>
    <meta charset="<?= Html::encode($applicationParams->charset) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= Html::encode($this->getTitle() . ' · ' . $applicationParams->name) ?></title>
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f3f4f6;color:#111827;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        main{width:100%;max-width:380px;margin:24px;padding:32px;background:#fff;border-radius:12px;box-shadow:0 10px 30px -10px rgba(0,0,0,.15)}
        h1{margin:0 0 24px;font-size:1.375rem}
        label{display:block;margin:0 0 4px;font-weight:600;font-size:.875rem}
        input{display:block;width:100%;box-sizing:border-box;margin:0 0 16px;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font:inherit}
        button{width:100%;padding:10px 12px;border:0;border-radius:8px;background:#2563eb;color:#fff;font:inherit;font-weight:600;cursor:pointer}
        .error{margin:0 0 16px;padding:10px 12px;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:.875rem}
        a{color:#2563eb}
    </style>
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>
<main><?= $content ?></main>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
