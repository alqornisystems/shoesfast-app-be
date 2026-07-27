# Invoice Share Link (Backend) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every order one public, expiring invoice URL that an admin copies and sends over WhatsApp, backed by an authenticated link-builder endpoint and a login-free invoice-read endpoint.

**Architecture:** Two new columns on `orders` (`invoice_token`, `invoice_expires_at`) hold the shared-link state. `OrderController@invoiceLink` (behind `auth:sanctum`, branch-scoped) mints the 40-char token once and pushes the expiry 30 days forward on every call. `PublicInvoiceController@show` sits outside the auth group with `throttle:60,1`, looks the order up by token with the branch scope explicitly disabled, and returns the whole invoice — header, branch, customer, items with per-treatment prices, and the payment numbers copied verbatim from `PaymentController@index`.

**Tech Stack:** Laravel 12, Sanctum token auth, MariaDB 10.4 (legacy schema), PHPUnit feature test on sqlite `:memory:`, Laravel Pint.

## Global Constraints

- Every `php` / `composer` / `pint` command must be prefixed with `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && …` — those binaries are not on the default PATH on this machine.
- Work on a feature branch: `git checkout -b feat/invoice-share-link`. Pushing `master` triggers an FTP deploy, so never commit this work straight onto `master`.
- Legacy schema conventions are mandatory: `protected $dateFormat = 'U'`, `const UPDATED_AT = 'modified_at'`, date columns cast to `integer`, soft delete via the `is_deleted` flag (never Laravel `SoftDeletes`), pluralized foreign keys (`orders_id`, `projects_id`, `orders_items_id`).
- New migrations must be idempotent against the already-migrated production DB: wrap every change in `Schema::hasTable(...)` / `Schema::hasColumn(...)`.
- Token: `Str::random(40)`, generated only when `invoice_token` is empty, **never** regenerated afterwards.
- Expiry: `time() + 30 * 86400`, re-set on **every** call to the link endpoint, new token or old.
- Money numbers (`due_date`, `total_paid`, `credit`, `payment_status`) are copied verbatim from `app/Http/Controllers/Api/PaymentController.php:77-105`. Do not re-derive them with a new formula.
- Custom action routes are declared **before** `Route::apiResource('orders', ...)` for the same resource.
- The public route lives **outside** the `auth:sanctum` group, next to the `webhook` route, with `->middleware('throttle:60,1')`.
- The public lookup is written explicitly as `Order::withoutBranchScope()->where('invoice_token', $token)` — never relying on `BranchContext::getActiveBranch()` happening to return `null` for guests.
- Public response messages are Indonesian and exact: 404 → `{ "message": "Invoice tidak ditemukan" }`, 410 → `{ "message": "Link invoice sudah kedaluwarsa", "branch": { "name", "whatsapp" } }`. The 410 carries the branch so the expired page can tell the customer who to contact — shop name and shop number only, never customer data.
- `branch.whatsapp` is `projects.whatsapp` falling back to `projects.phone` with `?:` (not `??`) — this legacy schema stores blanks as `''` at least as often as `NULL`. The frontend renders it as a `wa.me` link. There is no `branch.phone` key: one key, correctly named.
- The legacy tables (`orders`, `orders_items`, `treatments`, `payments`, `services`, `customers`, `projects`) have **no migrations** — production is the source of truth. The feature test therefore builds its own sqlite schema in `setUp()`. Never add `RefreshDatabase` to this test; `php artisan migrate` on a blank sqlite DB fails.
- Out of scope, do not build: server-side PDF rendering, WhatsApp/WAHA auto-send, link revocation before expiry, payload caching, 360-degree / multi-photo pipelines.
- Run `./vendor/bin/pint --dirty` before every commit (it is the project formatter).

## Convention compliance (CLAUDE.md)

Every point below was checked against the code blocks in this plan. A reviewer can follow the pointer and see it.

- **Unix integer timestamps** — `invoice_expires_at` is `$table->integer(...)` in the migration (Task 1) and cast `'invoice_expires_at' => 'integer'` in `Order::$casts` (Task 1). All timestamp columns in the test schema (`created_at`, `modified_at`, `date`, `invoice_expires_at`) are `integer` (Task 1). The controllers write and compare raw `time()` seconds, never `Carbon` (Tasks 3, 7).
- **`const UPDATED_AT = 'modified_at'` / `$dateFormat = 'U'`** — no new model is introduced, so nothing to declare; `Order` already carries both. The test schema gives every table a `created_at` **and** a `modified_at` integer column so Eloquent's automatic timestamps have somewhere to land (Task 1).
- **Soft delete via `is_deleted`** — no `SoftDeletes` anywhere in this plan. Every table in the test schema has `is_deleted` with `default(0)` so the models' `notDeleted` global scopes match the fixture rows (Task 1). The plan adds no `deleted_at` column and no `delete()` override.
- **Pluralised foreign keys** — `orders_id` (`orders_items`, `payments`), `projects_id` (every scoped table), `orders_items_id` (`treatments`), `customers_id`, `services_id`. Used verbatim in the test schema and fixture (Task 1) and in `Payment::where('orders_id', $order->id)` (Task 9).
- **Table names differ from model names** — applies to `OrderItem` → `orders_items` and `Treatment` → `treatments`; the test schema creates those exact table names rather than Laravel's guesses (Task 1). No new model is added, so no new `$table` needs declaring.
- **Idempotent migrations** — `up()` returns early on `! Schema::hasTable('orders')` and wraps each column in `! Schema::hasColumn(...)`; `down()` drops the unique index (`dropUnique('orders_invoice_token_unique')`) before dropping `invoice_token`, and guards each column with `Schema::hasColumn` (Task 1). Re-running against the already-migrated prod DB is a no-op, verified in Task 1 Step 5.
- **Branch scoping — authenticated side** — `invoiceLink` uses a plain `Order::findOrFail($id)` so the `BranchScoped` global scope stays on and a branch admin gets a 404 for another branch's order; the reason is stated in the method's docblock (Task 3).
- **Branch scoping — public side** — the single deliberate escape hatch is `Order::withoutBranchScope()->where('invoice_token', $token)` in `PublicInvoiceController@show`, with the comment explaining why the guest-returns-null behaviour of `BranchContext` is not leaned on (Task 6).
- **Never trust a client-supplied `projects_id`** — neither endpoint reads request input at all: `invoiceLink($id)` takes only the route id, `show($token)` only the token. No `Request` is injected, so there is nothing for a client to smuggle a `projects_id` through. No new `BranchScoped` model is added.
- **Route ordering** — `Route::post('orders/{id}/invoice-link', ...)` is shown in Task 3 Step 3 with the whole surrounding orders block, sitting above `Route::apiResource('orders', ...)` so `{id}` cannot shadow it.
- **API layer placement** — the new controller is `app/Http/Controllers/Api/PublicInvoiceController.php`, both routes go in `routes/api.php`, and the public route is shown in Task 6 Step 3 sandwiched between the `webhook` route and the `auth:sanctum` group — the second and last deliberate exception to the auth rule, throttled at `throttle:60,1`.
- **Formatting gate** — every task that writes PHP ends its commit step with `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit …` (Tasks 1-10).
- **Not applicable: `WhatsAppService` / `FcmService` / `ReportCacheService`** — this feature sends nothing and caches nothing (the spec rules out auto-send and payload caching), so no integration service is touched and no report cache needs invalidating: no report aggregation reads `invoice_token` or `invoice_expires_at`.
- **Not applicable: `composer dev` / Vite** — backend-only change, no frontend asset is built here.

---

## File Structure

| File | Created / Modified | Single responsibility |
| --- | --- | --- |
| `database/migrations/2026_07_27_100000_add_invoice_token_to_orders_table.php` | Create | Adds `invoice_token` + `invoice_expires_at` to `orders`, guarded so it is idempotent against prod. |
| `app/Models/Order.php` | Modify | Makes the two new columns mass-assignable and casts `invoice_expires_at` to `integer`. |
| `config/app.php` | Modify | Exposes `app.frontend_url` — the first origin of the comma-separated `FRONTEND_URL`. |
| `app/Http/Controllers/Api/OrderController.php` | Modify | Adds `invoiceLink($id)`: mint-once token, always-refreshed expiry, assembled URL. |
| `app/Http/Controllers/Api/PublicInvoiceController.php` | Create | Login-free `show($token)`: 404 / 410 / full invoice payload. |
| `routes/api.php` | Modify | Registers the authenticated link route (before `apiResource`) and the public throttled route (next to `webhook`). |
| `tests/Feature/InvoiceShareLinkTest.php` | Create | The single feature test: schema builder, fixture, and every assertion for both endpoints. |

---

### Task 1: Migration and Order model columns

**Files:**
- Create: `database/migrations/2026_07_27_100000_add_invoice_token_to_orders_table.php`
- Modify: `app/Models/Order.php:16-40`
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `orders.invoice_token` (string 40, nullable, unique), `orders.invoice_expires_at` (integer unix seconds, nullable); `Order` accepts both via mass assignment and casts `invoice_expires_at` to `int`. Test helpers `createLegacySchema(): void` and `seedInvoiceFixture(): Order` (order id 1, code `INV-2026-001`, `total_price` 195000, token `str_repeat('a', 40)`, expiry `time() + 86400`, 1 item with 2 treatments, 1 payment of 150000).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/InvoiceShareLinkTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Treatment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoiceShareLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacySchema();
    }

    public function test_order_stores_invoice_token_and_expiry(): void
    {
        $order = $this->seedInvoiceFixture();

        $fresh = Order::withoutBranchScope()->find($order->id);

        $this->assertSame(str_repeat('a', 40), $fresh->invoice_token);
        $this->assertIsInt($fresh->invoice_expires_at);
    }

    /**
     * The legacy tables have no migrations (production is the source of truth),
     * so the sqlite :memory: test DB is built by hand here.
     */
    private function createLegacySchema(): void
    {
        foreach (['projects', 'customers', 'orders', 'orders_items', 'services', 'treatments', 'payments'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('projects', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('whatsapp')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('customers_id')->nullable();
            $t->string('code')->nullable();
            $t->integer('date')->nullable();
            $t->integer('total_discount')->default(0);
            $t->integer('total_price')->default(0);
            $t->text('note')->nullable();
            $t->string('invoice_token', 40)->nullable()->unique();
            $t->integer('invoice_expires_at')->nullable();
            $t->tinyInteger('status')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('orders_items', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('orders_id')->nullable();
            $t->integer('services_id')->nullable();
            $t->text('photo')->nullable();
            $t->string('name')->nullable();
            $t->integer('price')->default(0);
            $t->integer('discount')->default(0);
            $t->tinyInteger('status')->default(0);
            $t->tinyInteger('type')->default(0);
            $t->text('checkbox')->nullable();
            $t->text('note')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('services', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->integer('price')->default(0);
            $t->integer('hpp')->nullable();
            $t->integer('estimation')->nullable();
            $t->text('photo')->nullable();
            $t->text('description')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('treatments', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('orders_items_id')->nullable();
            $t->integer('services_id')->nullable();
            $t->tinyInteger('status')->default(0);
            $t->integer('price')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('orders_id')->nullable();
            $t->integer('date')->nullable();
            $t->integer('nominal')->default(0);
            $t->text('note')->nullable();
            $t->text('photo')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });
    }

    /**
     * One branch, one customer, one order (195.000) with a single item that has
     * two priced treatments, plus a partial payment of 150.000.
     */
    private function seedInvoiceFixture(): Order
    {
        Project::create([
            'name' => 'Cabang Kemang',
            'phone' => '02177001100',
            'whatsapp' => '081277001100',
        ]);

        Customer::create([
            'projects_id' => 1,
            'name' => 'Budi Santoso',
            'phone' => '081200001111',
            'email' => null,
            'address' => 'Jl. Melati 10',
        ]);

        $order = Order::create([
            'projects_id' => 1,
            'customers_id' => 1,
            'code' => 'INV-2026-001',
            'date' => strtotime('2026-03-01 09:00:00'),
            'total_discount' => 0,
            'total_price' => 195000,
            'note' => null,
            'status' => 1,
            'invoice_token' => str_repeat('a', 40),
            'invoice_expires_at' => time() + 86400,
        ]);

        Service::create(['name' => 'Deep Clean', 'price' => 75000]);
        Service::create(['name' => 'Unyellowing', 'price' => 120000]);

        OrderItem::create([
            'projects_id' => 1,
            'orders_id' => $order->id,
            'services_id' => 1,
            'photo' => 'items/item-12-1.jpg',
            'name' => 'Nike Air Force 1',
            'price' => 195000,
            'discount' => 0,
            'status' => 1,
            'type' => 0,
        ]);

        Treatment::create([
            'projects_id' => 1,
            'orders_items_id' => 1,
            'services_id' => 1,
            'status' => 3,
            'price' => 75000,
        ]);

        Treatment::create([
            'projects_id' => 1,
            'orders_items_id' => 1,
            'services_id' => 2,
            'status' => 3,
            'price' => 120000,
        ]);

        Payment::create([
            'projects_id' => 1,
            'orders_id' => $order->id,
            'date' => strtotime('2026-03-02 10:00:00'),
            'nominal' => 150000,
            'note' => 'Transfer BCA',
        ]);

        return $order;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_order_stores_invoice_token_and_expiry`

Expected: FAIL — `Failed asserting that null is identical to 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'.` (`Order::$fillable` silently discards `invoice_token`).

- [ ] **Step 3: Write minimal implementation**

Create `database/migrations/2026_07_27_100000_add_invoice_token_to_orders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'invoice_token')) {
                $table->string('invoice_token', 40)->nullable()->unique()->after('note');
            }

            if (! Schema::hasColumn('orders', 'invoice_expires_at')) {
                $table->integer('invoice_expires_at')->nullable()->after('invoice_token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'invoice_token')) {
                $table->dropUnique('orders_invoice_token_unique');
                $table->dropColumn('invoice_token');
            }

            if (Schema::hasColumn('orders', 'invoice_expires_at')) {
                $table->dropColumn('invoice_expires_at');
            }
        });
    }
};
```

In `app/Models/Order.php`, replace the `$fillable` and `$casts` blocks (lines 16-40) with:

```php
    protected $fillable = [
        'projects_id',
        'customers_id',
        'code',
        'date',
        'total_discount',
        'total_price',
        'note',
        'status',
        'invoice_token',
        'invoice_expires_at',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'date' => 'integer',
        'total_discount' => 'integer',
        'total_price' => 'integer',
        'status' => 'integer',
        'invoice_expires_at' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'created_by' => 'integer',
        'modified_by' => 'integer',
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_order_stores_invoice_token_and_expiry`

Expected: PASS — 1 passed (2 assertions).

- [ ] **Step 5: Apply the migration to the local MariaDB and confirm both columns exist**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan migrate && php artisan tinker --execute="var_dump(Schema::hasColumn('orders','invoice_token'), Schema::hasColumn('orders','invoice_expires_at'));"`

Expected: migration reported as `DONE`, then `bool(true)` twice.

- [ ] **Step 6: Commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(invoice): add invoice_token and invoice_expires_at to orders"`

---

### Task 2: `app.frontend_url` config key

**Files:**
- Modify: `config/app.php:53`
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `config('app.frontend_url')` — a single origin string (first comma-separated entry of `FRONTEND_URL`, trimmed, `''` when unset).

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/InvoiceShareLinkTest.php`, directly below `test_order_stores_invoice_token_and_expiry`:

```php
    public function test_frontend_url_config_is_a_single_origin(): void
    {
        $url = config('app.frontend_url');

        $this->assertIsString($url);
        $this->assertNotSame('', $url);
        $this->assertStringNotContainsString(',', $url);
        $this->assertSame(trim(explode(',', (string) env('FRONTEND_URL', ''))[0]), $url);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_frontend_url_config_is_a_single_origin`

Expected: FAIL — `Failed asserting that null is of type string.` (the config key does not exist yet).

- [ ] **Step 3: Write minimal implementation**

In `config/app.php`, immediately after the `'url' => env('APP_URL', 'http://localhost'),` line, insert:

```php
    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | FRONTEND_URL boleh berisi beberapa origin dipisah koma (dipakai CORS).
    | Untuk merakit tautan (mis. /invoice/<token>) hanya origin pertama yang
    | dipakai. Disimpan di config, bukan env() di controller, supaya tetap
    | benar saat config:cache aktif di produksi.
    |
    */

    'frontend_url' => trim(explode(',', (string) env('FRONTEND_URL', ''))[0]),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan config:clear && php artisan test --filter=test_frontend_url_config_is_a_single_origin`

Expected: PASS — 1 passed (4 assertions).

- [ ] **Step 5: Commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(config): expose app.frontend_url as a single origin"`

---

### Task 3: `POST /api/orders/{id}/invoice-link` creates the link

**Files:**
- Modify: `app/Http/Controllers/Api/OrderController.php:14` (add `Str` import), `app/Http/Controllers/Api/OrderController.php:833-835` (append `invoiceLink` before the closing brace)
- Modify: `routes/api.php:71`
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: `createLegacySchema(): void`, `seedInvoiceFixture(): Order` (Task 1); `config('app.frontend_url')` (Task 2).
- Produces: `OrderController@invoiceLink($id)` → `200 { "url": "<frontend_url>/invoice/<40-char token>", "expires_at": <int> }`. Test helper `branchAdmin(int $projectId = 1): User`.

- [ ] **Step 1: Write the failing test**

Add the imports `use App\Models\User;` and `use Laravel\Sanctum\Sanctum;` to the top of `tests/Feature/InvoiceShareLinkTest.php`, then add these two methods below `test_frontend_url_config_is_a_single_origin`:

```php
    public function test_invoice_link_endpoint_creates_token_and_url(): void
    {
        $order = $this->seedInvoiceFixture();
        $order->update(['invoice_token' => null, 'invoice_expires_at' => null]);

        Sanctum::actingAs($this->branchAdmin());

        $body = $this->postJson('/api/orders/'.$order->id.'/invoice-link')
            ->assertStatus(200)
            ->json();

        $token = Order::withoutBranchScope()->find($order->id)->invoice_token;

        $this->assertSame(40, strlen($token));
        $this->assertSame(rtrim(config('app.frontend_url'), '/').'/invoice/'.$token, $body['url']);
        $this->assertEqualsWithDelta(time() + 30 * 86400, $body['expires_at'], 5);
    }

    private function branchAdmin(int $projectId = 1): User
    {
        $user = new User(['name' => 'Admin Kemang', 'projects_id' => $projectId]);
        $user->id = 1;

        return $user;
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_invoice_link_endpoint_creates_token_and_url`

Expected: FAIL — `Expected response status code [200] but received 404.` (the route does not exist).

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/Api/OrderController.php`, add below `use Illuminate\Support\Facades\Storage;`:

```php
use Illuminate\Support\Str;
```

and append this method just before the final closing brace of the class:

```php
    /**
     * Create or refresh the public invoice share link for an order.
     *
     * Branch scope stays ON: a branch admin must not be able to mint a link
     * for another branch's order.
     */
    public function invoiceLink($id)
    {
        $order = Order::findOrFail($id);

        $frontendUrl = (string) config('app.frontend_url');

        $order->invoice_token = Str::random(40);
        $order->invoice_expires_at = time() + 30 * 86400;
        $order->save();

        return response()->json([
            'url' => rtrim($frontendUrl, '/').'/invoice/'.$order->invoice_token,
            'expires_at' => $order->invoice_expires_at,
        ]);
    }
```

In `routes/api.php`, add the route inside the `auth:sanctum` group, in the orders block. It must sit **before** `Route::apiResource('orders', ...)` or `{id}` will shadow it. The block reads exactly like this afterwards (the new line is the last `Route::post`):

```php
    // Orders - custom routes before apiResource
    Route::get('orders/search/customers', [OrderController::class, 'searchCustomers']);
    Route::get('orders/search/services', [OrderController::class, 'searchServices']);
    Route::get('orders/available-pickup', [OrderController::class, 'getAvailablePickupOrders']);
    Route::get('orders/{orderId}/items', [OrderController::class, 'getItems']);
    Route::post('orders/{orderId}/items', [OrderController::class, 'saveItem']);
    Route::delete('orders/{orderId}/items/{itemId}', [OrderController::class, 'removeItem']);
    Route::post('orders/{id}/invoice-link', [OrderController::class, 'invoiceLink']);
    Route::apiResource('orders', OrderController::class);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_invoice_link_endpoint_creates_token_and_url`

Expected: PASS — 1 passed (3 assertions).

- [ ] **Step 5: Commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(invoice): add POST /orders/{id}/invoice-link"`

---

### Task 4: The token survives a refresh, the expiry does not

**Files:**
- Modify: `app/Http/Controllers/Api/OrderController.php` (inside `invoiceLink`)
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: `seedInvoiceFixture(): Order`, `branchAdmin(int $projectId = 1): User`, `OrderController@invoiceLink($id)`.
- Produces: `invoiceLink` keeps a non-empty `invoice_token` untouched and always writes `invoice_expires_at = time() + 30 * 86400`.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/InvoiceShareLinkTest.php` below `test_invoice_link_endpoint_creates_token_and_url`:

```php
    public function test_invoice_link_keeps_token_and_refreshes_expiry(): void
    {
        $order = $this->seedInvoiceFixture();

        Sanctum::actingAs($this->branchAdmin());

        $first = $this->postJson('/api/orders/'.$order->id.'/invoice-link')
            ->assertStatus(200)
            ->json();

        // Stale the expiry so a refresh is provable.
        Order::withoutBranchScope()->where('id', $order->id)->update(['invoice_expires_at' => 111]);

        $second = $this->postJson('/api/orders/'.$order->id.'/invoice-link')
            ->assertStatus(200)
            ->json();

        $this->assertSame($first['url'], $second['url']);
        $this->assertSame(
            $first['url'],
            rtrim(config('app.frontend_url'), '/').'/invoice/'.Order::withoutBranchScope()->find($order->id)->invoice_token
        );
        $this->assertGreaterThan(111, $second['expires_at']);
        $this->assertEqualsWithDelta(time() + 30 * 86400, $second['expires_at'], 5);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_invoice_link_keeps_token_and_refreshes_expiry`

Expected: FAIL — `Failed asserting that two strings are identical.` on the first `assertSame($first['url'], $second['url'])`, because the token is regenerated on every call.

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/Api/OrderController.php`, inside `invoiceLink`, replace the token assignment line:

```php
        $order->invoice_token = Str::random(40);
```

with:

```php
        // Mint once. Customers keep old links in their WhatsApp history, so an
        // existing token must never change — only the expiry moves forward.
        $order->invoice_token = $order->invoice_token ?: Str::random(40);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_invoice_link_keeps_token_and_refreshes_expiry`

Expected: PASS — 1 passed (4 assertions).

- [ ] **Step 5: Commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(invoice): keep the token stable, refresh only the expiry"`

---

### Task 5: Fail loudly when `FRONTEND_URL` is missing

**Files:**
- Modify: `app/Http/Controllers/Api/OrderController.php` (inside `invoiceLink`)
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: `seedInvoiceFixture(): Order`, `branchAdmin(int $projectId = 1): User`, `OrderController@invoiceLink($id)`.
- Produces: `invoiceLink` returns `500 { "message": "FRONTEND_URL belum dikonfigurasi, tautan invoice tidak bisa dibuat" }` and writes nothing when `config('app.frontend_url')` is empty.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/InvoiceShareLinkTest.php` below `test_invoice_link_keeps_token_and_refreshes_expiry`:

```php
    public function test_invoice_link_fails_loudly_when_frontend_url_missing(): void
    {
        $order = $this->seedInvoiceFixture();
        $order->update(['invoice_token' => null, 'invoice_expires_at' => null]);

        config(['app.frontend_url' => '']);

        Sanctum::actingAs($this->branchAdmin());

        $this->postJson('/api/orders/'.$order->id.'/invoice-link')
            ->assertStatus(500)
            ->assertJson(['message' => 'FRONTEND_URL belum dikonfigurasi, tautan invoice tidak bisa dibuat']);

        // Nothing was written: a half-made link is worse than no link.
        $this->assertNull(Order::withoutBranchScope()->find($order->id)->invoice_token);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_invoice_link_fails_loudly_when_frontend_url_missing`

Expected: FAIL — `Expected response status code [500] but received 200.`

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/Api/OrderController.php`, inside `invoiceLink`, insert the guard immediately after `$frontendUrl` is read and before the token is assigned:

```php
        if ($frontendUrl === '') {
            return response()->json([
                'message' => 'FRONTEND_URL belum dikonfigurasi, tautan invoice tidak bisa dibuat',
            ], 500);
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_invoice_link_fails_loudly_when_frontend_url_missing`

Expected: PASS — 1 passed (3 assertions).

- [ ] **Step 5: Commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(invoice): 500 with a clear message when FRONTEND_URL is empty"`

---

### Task 6: Public endpoint — route, invoice header, 404

**Files:**
- Create: `app/Http/Controllers/Api/PublicInvoiceController.php`
- Modify: `routes/api.php:16` (import), `routes/api.php:36` (public route next to `webhook`)
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: `createLegacySchema(): void`, `seedInvoiceFixture(): Order` (Task 1).
- Produces: `GET /api/public/invoice/{token}` → `PublicInvoiceController@show($token)`, returning `200 { code, date, branch: { name, whatsapp }, customer: { name, phone, email, address } }` or `404 { "message": "Invoice tidak ditemukan" }`.

- [ ] **Step 1: Write the failing test**

Add these two methods to `tests/Feature/InvoiceShareLinkTest.php` below `test_invoice_link_fails_loudly_when_frontend_url_missing`:

```php
    public function test_public_invoice_returns_invoice_header(): void
    {
        $order = $this->seedInvoiceFixture();

        $this->getJson('/api/public/invoice/'.$order->invoice_token)
            ->assertStatus(200)
            ->assertJsonPath('code', 'INV-2026-001')
            ->assertJsonPath('date', strtotime('2026-03-01 09:00:00'))
            ->assertJsonPath('branch.name', 'Cabang Kemang')
            ->assertJsonPath('branch.whatsapp', '081277001100')
            ->assertJsonPath('customer.name', 'Budi Santoso')
            ->assertJsonPath('customer.phone', '081200001111')
            ->assertJsonPath('customer.email', null)
            ->assertJsonPath('customer.address', 'Jl. Melati 10');
    }

    public function test_public_invoice_returns_404_for_unknown_token(): void
    {
        $this->seedInvoiceFixture();

        $this->getJson('/api/public/invoice/'.str_repeat('z', 40))
            ->assertStatus(404)
            ->assertJson(['message' => 'Invoice tidak ditemukan']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_public_invoice_returns`

Expected: FAIL — both methods fail; the first with `Expected response status code [200] but received 404.`, the second with a JSON mismatch showing Laravel's `The route api/public/invoice/zzz... could not be found.` instead of `Invoice tidak ditemukan`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Http/Controllers/Api/PublicInvoiceController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;

class PublicInvoiceController extends Controller
{
    /**
     * Public, login-free invoice read.
     *
     * The branch scope is dropped explicitly. BranchContext happens to return
     * null for guests today, but leaning on that would make this endpoint
     * silently depend on behaviour nobody promised.
     */
    public function show($token)
    {
        $order = Order::withoutBranchScope()
            ->with(['customer', 'project'])
            ->where('invoice_token', $token)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Invoice tidak ditemukan'], 404);
        }

        return response()->json([
            'code' => $order->code,
            'date' => $order->date,
            'branch' => [
                'name' => $order->project->name,
                'whatsapp' => $order->project->whatsapp,
            ],
            'customer' => [
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'email' => $order->customer->email,
                'address' => $order->customer->address,
            ],
        ]);
    }
}
```

In `routes/api.php`, add the import next to the other controller imports:

```php
use App\Http\Controllers\Api\PublicInvoiceController;
```

and register the route **outside** the `auth:sanctum` group, directly under the `webhook` route — the only other deliberate exception to "everything is behind `auth:sanctum`". That stretch of the file reads exactly like this afterwards:

```php
// Wablas incoming-message webhook (publik; opsional diverifikasi WABLAS_WEBHOOK_SECRET)
Route::post('webhook', [WebhookController::class, 'whatsapp']);

// Invoice publik (tanpa login) — dibuka customer dari tautan WhatsApp.
// Throttle supaya token 40 karakter tidak bisa digempur brute force.
Route::get('public/invoice/{token}', [PublicInvoiceController::class, 'show'])
    ->middleware('throttle:60,1');

// Protected
Route::middleware('auth:sanctum')->group(function () {
```

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_public_invoice_returns`

Expected: PASS — 2 passed.

- [ ] **Step 5: Commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(invoice): add public GET /public/invoice/{token}"`

---

### Task 7: Expired links return 410 with the branch to contact

**Files:**
- Modify: `app/Http/Controllers/Api/PublicInvoiceController.php` (inside `show`)
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: `seedInvoiceFixture(): Order`, `PublicInvoiceController@show($token)` (Task 6).
- Produces: `410 { "message": "Link invoice sudah kedaluwarsa", "branch": { "name", "whatsapp" } }` when `invoice_expires_at` is empty or in the past. Also introduces the local `$branch` array — null-safe (`?->`) with `whatsapp` falling back to `phone` via `?:` — that the 200 payload reuses from here on.

- [ ] **Step 1: Write the failing test**

Add these three methods to `tests/Feature/InvoiceShareLinkTest.php` below `test_public_invoice_returns_404_for_unknown_token`:

```php
    public function test_public_invoice_returns_410_when_link_expired(): void
    {
        $order = $this->seedInvoiceFixture();
        $order->update(['invoice_expires_at' => time() - 60]);

        // The expired page has to tell the customer who to contact: shop name
        // and shop number go out, nothing of the customer's own data does.
        $this->getJson('/api/public/invoice/'.$order->invoice_token)
            ->assertStatus(410)
            ->assertJson(['message' => 'Link invoice sudah kedaluwarsa'])
            ->assertJsonPath('branch.name', 'Cabang Kemang')
            ->assertJsonPath('branch.whatsapp', '081277001100')
            ->assertJsonMissingPath('customer');
    }

    public function test_public_invoice_410_falls_back_to_branch_phone(): void
    {
        $order = $this->seedInvoiceFixture();
        $order->update(['invoice_expires_at' => time() - 60]);

        // This legacy schema stores "never filled in" as '' at least as often
        // as NULL, so the fallback has to be ?: and not ??.
        Project::find(1)->update(['whatsapp' => '']);

        $this->getJson('/api/public/invoice/'.$order->invoice_token)
            ->assertStatus(410)
            ->assertJsonPath('branch.whatsapp', '02177001100');
    }

    public function test_public_invoice_returns_410_when_expiry_never_set(): void
    {
        $order = $this->seedInvoiceFixture();
        $order->update(['invoice_expires_at' => null]);

        $this->getJson('/api/public/invoice/'.$order->invoice_token)
            ->assertStatus(410)
            ->assertJson(['message' => 'Link invoice sudah kedaluwarsa'])
            ->assertJsonPath('branch.name', 'Cabang Kemang')
            ->assertJsonPath('branch.whatsapp', '081277001100');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter='test_public_invoice_(returns_410|410_falls_back)'`

Expected: FAIL — all three with `Expected response status code [410] but received 200.`

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/Api/PublicInvoiceController.php`, insert this block immediately after the `if (! $order)` block. The branch is built once and reused by the 200 payload so the two responses can never drift apart:

```php
        // Null-safe: the projects row may be gone. Keep the keys either way so
        // the frontend never has to guard.
        $branch = [
            'name' => $order->project?->name,
            // Rendered as a wa.me link on the public page, so prefer the
            // WhatsApp number; fall back to the landline for branches that
            // never set one. ?: not ??, because this legacy schema stores
            // blanks as '' at least as often as NULL.
            'whatsapp' => $order->project?->whatsapp ?: $order->project?->phone,
        ];

        if (! $order->invoice_expires_at || $order->invoice_expires_at < time()) {
            return response()->json([
                'message' => 'Link invoice sudah kedaluwarsa',
                'branch' => $branch,
            ], 410);
        }
```

Then, in the same file, replace the inline branch entry of the 200 payload:

```php
            'branch' => [
                'name' => $order->project->name,
                'whatsapp' => $order->project->whatsapp,
            ],
```

with:

```php
            'branch' => $branch,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter='test_public_invoice_(returns_410|410_falls_back)'`

Expected: PASS — 3 passed (12 assertions).

- [ ] **Step 5: Commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(invoice): return 410 with the branch contact for expired links"`

---

### Task 8: Items, per-treatment prices, photo path to URL

**Files:**
- Modify: `app/Http/Controllers/Api/PublicInvoiceController.php` (inside `show`)
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: `seedInvoiceFixture(): Order`, `PublicInvoiceController@show($token)` (Task 6).
- Produces: payload key `items: [{ name, photo, price, discount, treatments: [{ name, price }] }]`, photo converted with the same rule as `OrderController.php:123-137`.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/InvoiceShareLinkTest.php` below `test_public_invoice_returns_410_when_expiry_never_set`:

```php
    public function test_public_invoice_returns_items_with_treatments_and_photo_url(): void
    {
        $order = $this->seedInvoiceFixture();

        // Second item: photo already stored as an absolute URL, must pass through.
        OrderItem::create([
            'projects_id' => 1,
            'orders_id' => $order->id,
            'services_id' => 2,
            'photo' => 'https://cdn.example.com/sepatu.jpg',
            'name' => 'Adidas Samba',
            'price' => 80000,
            'discount' => 5000,
            'status' => 1,
            'type' => 0,
        ]);

        $body = $this->getJson('/api/public/invoice/'.$order->invoice_token)
            ->assertStatus(200)
            ->json();

        $this->assertCount(2, $body['items']);

        $this->assertSame('Nike Air Force 1', $body['items'][0]['name']);
        $this->assertSame(195000, $body['items'][0]['price']);
        $this->assertSame(0, $body['items'][0]['discount']);
        $this->assertStringStartsWith('http', $body['items'][0]['photo']);
        $this->assertStringEndsWith('/storage/items/item-12-1.jpg', $body['items'][0]['photo']);
        $this->assertSame(
            [
                ['name' => 'Deep Clean', 'price' => 75000],
                ['name' => 'Unyellowing', 'price' => 120000],
            ],
            $body['items'][0]['treatments']
        );

        $this->assertSame('https://cdn.example.com/sepatu.jpg', $body['items'][1]['photo']);
        $this->assertSame([], $body['items'][1]['treatments']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_public_invoice_returns_items_with_treatments_and_photo_url`

Expected: FAIL — `Undefined array key "items"` (the payload has no `items` key yet).

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/Api/PublicInvoiceController.php`, extend the eager load and add the item transform. Replace the `->with(['customer', 'project'])` line with:

```php
            ->with(['customer', 'project', 'items.treatments.service'])
```

Insert this block after the 410 guard and before the `return response()->json([...])`:

```php
        // Same path -> URL rule as OrderController::show (OrderController.php:123-137).
        $items = $order->items->map(function ($item) {
            $photoUrl = null;
            if ($item->photo) {
                if (filter_var($item->photo, FILTER_VALIDATE_URL)) {
                    $photoUrl = $item->photo;
                } else {
                    $photoUrl = asset('storage/'.$item->photo);
                }
            }

            return [
                'name' => $item->name,
                'photo' => $photoUrl,
                'price' => $item->price,
                'discount' => $item->discount,
                'treatments' => $item->treatments->map(function ($treatment) {
                    return [
                        'name' => $treatment->service ? $treatment->service->name : null,
                        'price' => $treatment->price,
                    ];
                })->values(),
            ];
        })->values();
```

and add `'items' => $items,` to the returned array, right after the `customer` key.

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_public_invoice_returns_items_with_treatments_and_photo_url`

Expected: PASS — 1 passed (9 assertions).

- [ ] **Step 5: Commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(invoice): add items, per-treatment prices and photo URLs to the public payload"`

---

### Task 9: Money numbers copied from PaymentController + payment history

**Files:**
- Modify: `app/Http/Controllers/Api/PublicInvoiceController.php` (inside `show`)
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: `seedInvoiceFixture(): Order`, `branchAdmin(int $projectId = 1): User` (Task 3), `PublicInvoiceController@show($token)` (Task 6).
- Produces: payload keys `due_date`, `payment_status`, `total_price`, `total_paid`, `credit`, and `payments: [{ date, nominal, note }]` ordered by `date` ascending — numerically identical to `PaymentController@index`.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/InvoiceShareLinkTest.php` below `test_public_invoice_returns_items_with_treatments_and_photo_url`:

```php
    public function test_public_invoice_money_matches_payment_controller(): void
    {
        $order = $this->seedInvoiceFixture();

        $public = $this->getJson('/api/public/invoice/'.$order->invoice_token)
            ->assertStatus(200)
            ->json();

        Sanctum::actingAs($this->branchAdmin());

        $row = collect($this->getJson('/api/payments')->assertStatus(200)->json('data'))
            ->firstWhere('code', 'INV-2026-001');

        $this->assertSame($row['due_date'], $public['due_date']);
        $this->assertSame($row['total_price'], $public['total_price']);
        $this->assertSame($row['total_paid'], $public['total_paid']);
        $this->assertSame($row['credit'], $public['credit']);
        $this->assertSame($row['payment_status'], $public['payment_status']);

        $this->assertSame(strtotime('2026-03-04 00:00:00'), $public['due_date']);
        $this->assertSame(195000, $public['total_price']);
        $this->assertSame(150000, $public['total_paid']);
        $this->assertSame(45000, $public['credit']);
        $this->assertSame('partial', $public['payment_status']);

        $this->assertSame(
            [['date' => strtotime('2026-03-02 10:00:00'), 'nominal' => 150000, 'note' => 'Transfer BCA']],
            $public['payments']
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_public_invoice_money_matches_payment_controller`

Expected: FAIL — `Undefined array key "due_date"` (the payload carries no money fields yet).

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/Api/PublicInvoiceController.php`, add `use App\Models\Payment;` under the `use App\Models\Order;` import, extend the eager load so the payment history comes back ordered:

```php
            ->with(['customer', 'project', 'items.treatments.service', 'payments' => function ($q) {
                $q->orderBy('date');
            }])
```

Insert this block right after the `$items` transform:

```php
        // Copied verbatim from PaymentController::index (PaymentController.php:77-105)
        // so the public invoice and the payments page can never disagree.
        $dueDate = strtotime(date('Y-m-d', strtotime(date('Y-m-d', $order->date).' +3 days')));
        $totalPaid = Payment::where('orders_id', $order->id)->sum('nominal');
        $credit = $order->total_price - $totalPaid;
        $paymentStatus = $credit === 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid');
```

and extend the returned array so it reads:

```php
        return response()->json([
            'code' => $order->code,
            'date' => $order->date,
            'due_date' => $dueDate,
            'payment_status' => $paymentStatus,
            'branch' => $branch,
            'customer' => [
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'email' => $order->customer->email,
                'address' => $order->customer->address,
            ],
            'items' => $items,
            'total_price' => $order->total_price,
            'total_paid' => $totalPaid,
            'credit' => $credit,
            'payments' => $order->payments->map(function ($payment) {
                return [
                    'date' => $payment->date,
                    'nominal' => $payment->nominal,
                    'note' => $payment->note,
                ];
            })->values(),
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_public_invoice_money_matches_payment_controller`

Expected: PASS — 1 passed (11 assertions).

- [ ] **Step 5: Commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(invoice): add payment totals and history to the public payload"`

---

### Task 10: Edge cases — walk-in orders, missing relations, no auth header

**Files:**
- Modify: `app/Http/Controllers/Api/PublicInvoiceController.php` (inside `show`)
- Test: `tests/Feature/InvoiceShareLinkTest.php`

**Interfaces:**
- Consumes: `createLegacySchema(): void`, `PublicInvoiceController@show($token)` (Task 6), the null-safe `$branch` array (Task 7).
- Produces: `customer` always present as an object with `null` fields when the relation is missing (`branch` already is, from Task 7); `items: []` for an order with no items; `photo: null` when the item has no photo.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/InvoiceShareLinkTest.php` below `test_public_invoice_money_matches_payment_controller`:

```php
    public function test_public_invoice_survives_missing_relations_without_auth(): void
    {
        // Walk-in order: no customer, branch row gone, no items, no payments.
        $order = Order::create([
            'projects_id' => 99,
            'customers_id' => null,
            'code' => 'INV-2026-002',
            'date' => strtotime('2026-03-01 09:00:00'),
            'total_discount' => 0,
            'total_price' => 50000,
            'status' => 1,
            'invoice_token' => str_repeat('b', 40),
            'invoice_expires_at' => time() + 86400,
        ]);

        // No Sanctum::actingAs here on purpose: the endpoint must answer without
        // an Authorization header.
        $this->getJson('/api/public/invoice/'.$order->invoice_token)
            ->assertStatus(200)
            ->assertJsonPath('branch.name', null)
            ->assertJsonPath('branch.whatsapp', null)
            ->assertJsonPath('customer.name', null)
            ->assertJsonPath('customer.phone', null)
            ->assertJsonPath('customer.email', null)
            ->assertJsonPath('customer.address', null)
            ->assertJsonPath('items', [])
            ->assertJsonPath('payments', [])
            ->assertJsonPath('total_paid', 0)
            ->assertJsonPath('credit', 50000)
            ->assertJsonPath('payment_status', 'unpaid');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_public_invoice_survives_missing_relations_without_auth`

Expected: FAIL — `Expected response status code [200] but received 500.`, caused by `Attempt to read property "name" on null` on `$order->customer->name` (`branch` is already null-safe since Task 7).

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/Api/PublicInvoiceController.php`, make the customer block of the 200 payload null-safe — the keys stay so the frontend never has to guard:

```php
            'customer' => [
                'name' => $order->customer?->name,
                'phone' => $order->customer?->phone,
                'email' => $order->customer?->email,
                'address' => $order->customer?->address,
            ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && php artisan test --filter=test_public_invoice_survives_missing_relations_without_auth`

Expected: PASS — 1 passed (12 assertions).

- [ ] **Step 5: Run the whole suite and commit**

Run: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH" && composer test && ./vendor/bin/pint --dirty && git add -A && git commit -m "feat(invoice): keep customer keys present when the relation is missing"`

Expected: the full suite passes (2 existing example tests + 13 invoice-share-link tests), then the commit succeeds.

---

## Done means

- `composer test` is green.
- `POST /api/orders/{id}/invoice-link` returns a `<frontend_url>/invoice/<40 chars>` URL, mints the token once, and pushes `invoice_expires_at` 30 days out on every call.
- `GET /api/public/invoice/{token}` answers 200 / 404 / 410 without any `Authorization` header, throttled at 60 requests per minute, and the 410 carries `branch` so the expired page can show a contact.
- `php artisan migrate` is idempotent: re-running it against a DB that already has both columns is a no-op.
