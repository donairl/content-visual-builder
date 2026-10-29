<?php

declare(strict_types=1);

return [
    '@root' => dirname(__DIR__, 2),
    '@src' => '@root/src',
    // The shared editor/backend contract. Read in place at runtime, never copied.
    '@schema' => '@root/../schema',
    '@assets' => '@root/public/assets',
    '@assetsUrl' => '@baseUrl/assets',
    '@assetsSource' => '@root/assets',
    '@baseUrl' => '/',
    '@public' => '@root/public',
    '@runtime' => '@root/runtime',
    '@vendor' => '@root/vendor',
];
