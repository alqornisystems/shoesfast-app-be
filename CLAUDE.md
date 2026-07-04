# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Shoesfast backend — a Laravel 12 REST API (Sanctum token auth, no Blade UI) for a shoe-care/repair business. Core domain flow: **orders → order items → treatments (technician work) → sends (delivery/pickup) → payments**, plus expenses, reports, attendance/payroll, and WhatsApp broadcasts. The API is consumed by separate frontend apps (`FRONTEND_URL`).

## Local environment (this machine)

PHP and MySQL come from **XAMPP**, and their binaries are **not** on the default non-interactive `PATH`. Every `Bash` tool invocation that needs `php`, `composer`, or `mysql` must prepend XAMPP first:

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
```

- PHP: 8.2.4 (matches prod `^8.2`) · Composer: global at `/usr/local/bin/composer` · DB: MariaDB 10.4 (XAMPP)
- Local DB `shoesfast`, user `root`, empty password. mysql client: `/Applications/XAMPP/xamppfiles/bin/mysql`
- `.env` is local (`APP_ENV=local`); the production `.env` is backed up at `.env.production.bak`. `WABLAS_ENABLED`/`FCM_ENABLED` are `false` locally so tests don't hit real WhatsApp/FCM.

## Commands

```bash
composer dev          # runs serve + queue:listen + pail (logs) + vite concurrently
php artisan serve     # API only, http://localhost:8000

composer test                       # config:clear then artisan test
php artisan test --filter=SomeTest  # single test / method
./vendor/bin/pint                   # format (Laravel Pint) — the linter/formatter

php artisan migrate                 # apply migrations
php artisan tinker                  # REPL
```

## Deployment

Push to `master` → GitHub Actions (`.github/workflows/deploy.yml`) uploads **only changed files** to the prod server via FTP (secrets `FTP_*`). `vendor/` and `.env` are gitignored and managed manually on the server; a `composer.json` change still requires `composer install` on the server. `.github/` is excluded from the upload.

## Architecture

### Multi-branch (multi-tenant) scoping — the central pattern

A "branch" is a row in the `projects` table; `projects_id` is the tenant key on most tables.

- **`App\Traits\BranchScoped`** (add to any model with a `projects_id` column): a global scope auto-filters queries to the active branch, and `creating` auto-assigns `projects_id`. Escape hatches: `Model::withoutBranchScope()` and `Model::forBranch($id)`.
- **`App\Services\BranchContext`** (singleton bound as `branch.context`, resolve via `app('branch.context')`): determines the active branch. A **branch user** (`users.projects_id` set) is locked to their branch. A **super admin** (`projects_id = null`) sees all branches, or one at a time by switching via `UserPreference.active_branch_id` (persisted in DB, not session).

When adding a tenant-scoped model or endpoint, apply `BranchScoped` and never trust a client-supplied `projects_id`.

### Non-standard Eloquent conventions (legacy schema — production DB is the source of truth)

Models deviate from Laravel defaults; follow the existing model when adding one:

- **Unix integer timestamps**: `protected $dateFormat = 'U'`, and `const UPDATED_AT = 'modified_at'` (there is no `updated_at` column). Date/time columns are cast to `integer`.
- **Soft delete via `is_deleted` flag**, not Laravel `SoftDeletes`. A global scope filters `is_deleted = 0`, and models override `delete()` to set `is_deleted = 1`.
- **Foreign keys are pluralized**: `users_id`, `projects_id` (not `user_id`).
- **Table names may differ from the model name** — e.g. `App\Models\DailyNote` maps to the `issues` table (`protected $table`). Always check `$table` before assuming.

Because prod is authoritative, new migrations must match the existing schema. When altering legacy tables, guard changes (`Schema::hasTable`/`Schema::hasColumn`) so they stay idempotent against the already-migrated prod DB.

### API layer

- All routes are in `routes/api.php`; controllers in `app/Http/Controllers/Api`. Laravel 12 wires routing/middleware in `bootstrap/app.php` (CORS is prepended to the `api` group).
- Everything except `auth/login` and the public `webhook*` endpoints sits behind `auth:sanctum`.
- **Custom action routes are declared _before_ `Route::apiResource(...)`** for the same resource (e.g. `orders/search/customers` before `apiResource('orders', ...)`) so they aren't shadowed by `{id}` — preserve this ordering.

### Integrations (`app/Services`)

- **`WhatsAppService`** — outbound WhatsApp via **WhatsApp Cloud API (Meta)**. Gated by `WHATSAPP_ENABLED` + `WHATSAPP_TOKEN`/`WHATSAPP_PHONE_NUMBER_ID`. Inbound (reading messages) via `WebhookController@whatsapp`: a single `GET|POST /api/webhook` route — GET answers Meta's subscription challenge (`WHATSAPP_VERIFY_TOKEN`), POST parses the Cloud API payload (`entry.0.changes.0.value.messages.0`) and drives auto-reply / auto-register-customer / auto-create-order.
- **`FcmService`** — push notifications. Gated by `FCM_ENABLED`.
- **`ReportCacheService`** — caches report aggregations (cache store is the `database` driver).

Sessions, cache, and queue all use the `database` driver (see the `sessions`, `cache`, `jobs` tables).
