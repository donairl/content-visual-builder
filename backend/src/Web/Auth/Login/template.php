<?php

declare(strict_types=1);

use Yiisoft\Html\Html;

/**
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Yii\View\Renderer\Csrf $csrf
 * @var string $email
 * @var string $return
 * @var string|null $error
 */

$this->setTitle('Sign in');
?>
<h1>Sign in</h1>
<?php if ($error !== null): ?>
    <p class="error" role="alert"><?= Html::encode($error) ?></p>
<?php endif ?>
<form method="post" action="/login?<?= Html::encode(http_build_query(['return' => $return])) ?>">
    <?= $csrf->hiddenInput() ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required value="<?= Html::encode($email) ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
</form>
