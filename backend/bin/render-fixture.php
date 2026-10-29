#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Parity CLI for the editor's tests: renders a document with the backend's own renderer classes.
 *
 *   php bin/render-fixture.php <document.json>             → {"html": "…", "css": "…"}
 *   php bin/render-fixture.php --validate <document.json>  → {"errors": [{"path", "message"}]}
 *   php bin/render-fixture.php --validate-globals <globals.json> → {"errors": [...]}
 *
 * `--context=<file>` renders against site data: `{"globals": {…}, "components": {"<id>": document}}`
 * (README §9, §10).
 *
 * `html` is the fragment that goes inside `<div class="cvb-root">`, `css` the publish-mode CSS.
 * No database, no framework container: only `schema/` (or `--schema=<dir>`) is read.
 * Exit codes: 0 success, 1 unreadable input, 2 usage error.
 */

use App\Domain\Document\DocumentValidator;
use App\Domain\Document\GlobalsValidator;
use App\Domain\Document\Schema;
use App\Domain\Render\CssGenerator;
use App\Domain\Render\PageRenderer;
use App\Domain\Render\RichTextSanitizer;
use App\Domain\Render\SiteContext;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$schemaDir = dirname(__DIR__, 2) . '/schema';
$validate = false;
$validateGlobals = false;
$contextFile = null;
$file = null;

/** @var list<string> $argv */
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--validate') {
        $validate = true;
    } elseif ($arg === '--validate-globals') {
        $validateGlobals = true;
    } elseif (str_starts_with($arg, '--context=')) {
        $contextFile = substr($arg, strlen('--context='));
    } elseif (str_starts_with($arg, '--schema=')) {
        $schemaDir = substr($arg, strlen('--schema='));
    } elseif ($file === null && !str_starts_with($arg, '--')) {
        $file = $arg;
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}

if ($file === null) {
    fwrite(STDERR, "Usage: php bin/render-fixture.php [--validate] [--schema=<dir>] <document.json|->\n");
    exit(2);
}

$raw = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
if (!is_string($raw)) {
    fwrite(STDERR, "Cannot read $file\n");
    exit(1);
}

try {
    $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'Invalid JSON: ' . $e->getMessage() . "\n");
    exit(1);
}

$schema = Schema::fromDirectory($schemaDir);
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

if ($validate) {
    echo json_encode(['errors' => (new DocumentValidator($schema))->validate($document, strlen($raw))], $flags), "\n";
    exit(0);
}
if ($validateGlobals) {
    echo json_encode(['errors' => (new GlobalsValidator($schema))->validate($document)], $flags), "\n";
    exit(0);
}

$context = [];
if ($contextFile !== null) {
    $contextRaw = @file_get_contents($contextFile);
    if (!is_string($contextRaw)) {
        fwrite(STDERR, "Cannot read $contextFile\n");
        exit(1);
    }
    $context = json_decode($contextRaw, true, 512, JSON_THROW_ON_ERROR);
}
$context = is_array($context) ? $context : [];
$components = [];
foreach (is_array($context['components'] ?? null) ? $context['components'] : [] as $id => $component) {
    $components[(int) $id] = $component;
}
$site = SiteContext::fromGlobals($schema, $context['globals'] ?? null)->withComponents($components);

echo json_encode([
    'html' => (new PageRenderer($schema, new RichTextSanitizer($schema)))->render($document, $site),
    'css' => (new CssGenerator($schema))->generate($document, $site),
], $flags), "\n";
