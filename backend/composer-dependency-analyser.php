<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$root = __DIR__;
return (new Configuration())
    ->disableComposerAutoloadPathScan()
    ->setFileExtensions(['php'])
    ->addPathToScan($root . '/src', isDev: false)
    ->addPathToExclude($root . '/src/bootstrap.php')
    ->addPathToScan($root . '/src/bootstrap.php', isDev: true)
    ->addPathToScan($root . '/config', isDev: false)
    ->addPathToScan($root . '/public/index.php', isDev: false)
    ->addPathToScan($root . '/yii', isDev: false)
    ->addPathToScan($root . '/tests', isDev: true)
    ->ignoreErrorsOnPackage('psr/container', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('yiisoft/config', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('yiisoft/di', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('yiisoft/view', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('yiisoft/router-fastroute', [ErrorType::UNUSED_DEPENDENCY])
    // Installed for an opt-in persistent DB schema cache; nothing references it yet.
    ->ignoreErrorsOnPackage('yiisoft/cache-file', [ErrorType::UNUSED_DEPENDENCY])
    // Optional: PageForm::slugify() falls back to iconv when intl is missing (see "suggest").
    ->ignoreErrorsOnExtension('ext-intl', [ErrorType::SHADOW_DEPENDENCY])
    // Needed at runtime by yiisoft/session; only the tests call session functions directly.
    ->ignoreErrorsOnExtension('ext-session', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV]);
