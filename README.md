# Content Visual Builder

An Elementor-style visual page builder: drag-and-drop editing with live, responsive preview,
saved as a re-editable JSON document and published as fast, script-free HTML5 pages.

```
editor/    Next.js + TypeScript editor (static export, served by the backend under /editor)
backend/   Yii3 (PHP 8.2+) REST API, auth, publishing and public page rendering
schema/    The shared contract: document format, widgets, styles, rendering rules, fixtures
```

`schema/README.md` is the normative spec. Both sides load the same JSON files and are tested
against the same fixtures, including a cross-language test that renders every fixture in PHP and
TypeScript and requires identical CSS and DOM.

## How it fits together

- **Editing** happens entirely in the browser. The page is a flat map of elements (`nodes`) with
  responsive style values per device. State lives in a small local store (no state library);
  every edit is an immutable operation, which gives cheap undo/redo snapshots.
- **Canvas** renders the real widget markup with the real CSS. Device preview swaps the active
  responsive rules (desktop → tablet → mobile cascade) instead of relying on the canvas width.
- **Saving** is automatic (debounced, one request at a time, compare-and-swap on a version
  number). Failures never discard edits: retryable errors back off, conflicts and expired sessions
  wait for a decision, and every unsaved revision is mirrored to `localStorage`.
- **Publishing** re-validates the stored draft on the server, renders HTML + CSS in PHP and stores
  the result. Public pages (`/{slug}`) are served from that snapshot with a strict CSP and no
  JavaScript. Drafts never affect the live page until the next publish.
- **Security**: capability checks on every API action (admin/editor/author roles, ownership rules),
  CSRF token on every state-changing request, strict server-side validation of every value, rich
  text sanitized on save and on render, URL allowlists, no SVG uploads.

## Requirements

- PHP 8.2–8.5 with `pdo_sqlite` (or `pdo_pgsql` / `pdo_mysql`), Composer
- Node.js 20+ and npm

## Quick start (dev mode, one command)

```bash
./serve.sh        # installs deps if needed, migrates, seeds admin@example.com / admin12345
```

Open **http://127.0.0.1:3000/editor/**. Ports and credentials: `BACKEND_PORT`, `EDITOR_PORT`,
`ADMIN_EMAIL`, `ADMIN_PASSWORD`. Ctrl+C stops both servers.

## Setup (manual)

```bash
# backend
cd backend
composer install
cp .env.example .env              # DB_DRIVER=sqlite by default
./yii migrate:up
./yii user:create admin@example.com "Admin" --role=admin

# editor (builds the static export into backend/public/editor)
cd ../editor
npm install
npm run build

# run
cd ../backend && composer serve   # http://127.0.0.1:8080 → /editor/
```

For editor development run `npm run dev` in `editor/` (http://localhost:3000/editor/); it proxies
API, login and preview requests to the backend on port 8080, so both share one origin.

## Tests

```bash
cd editor
npm test              # unit + parity tests (vitest)
npm run typecheck
npm run e2e           # Playwright against the real backend (needs npm run build first)

cd ../backend
composer test         # Codeception: unit + functional
```

## Status

Scope 1 (editor canvas, drag-and-drop, basic widgets, properties panel, responsive preview,
undo/redo, autosave and recovery, publish, frontend rendering) is implemented. Scope 2 (reusable
components, global styles, navigator drag-and-drop, template library, form widget, custom CSS,
copy-paste style, revision history) is not started.
