<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * AMAN untuk database yang sudah ada data:
     * - Indexes tidak merusak data
     * - Hanya mempercepat query
     * - Bisa rollback dengan aman
     * - Menggunakan raw SQL untuk kompatibilitas Laravel 12
     */
    public function up(): void
    {
        // ====== ORDERS TABLE ======
        if (!$this->indexExists('orders', 'idx_orders_date')) {
            DB::statement('CREATE INDEX idx_orders_date ON orders(date)');
        }
        if (!$this->indexExists('orders', 'idx_orders_status')) {
            DB::statement('CREATE INDEX idx_orders_status ON orders(status)');
        }
        if (!$this->indexExists('orders', 'idx_orders_branch')) {
            DB::statement('CREATE INDEX idx_orders_branch ON orders(projects_id)');
        }
        if (!$this->indexExists('orders', 'idx_orders_deleted')) {
            DB::statement('CREATE INDEX idx_orders_deleted ON orders(is_deleted)');
        }
        if (!$this->indexExists('orders', 'idx_orders_date_status_deleted')) {
            DB::statement('CREATE INDEX idx_orders_date_status_deleted ON orders(date, status, is_deleted)');
        }

        // ====== PAYMENTS TABLE ======
        if (!$this->indexExists('payments', 'idx_payments_date')) {
            DB::statement('CREATE INDEX idx_payments_date ON payments(date)');
        }
        if (!$this->indexExists('payments', 'idx_payments_order')) {
            DB::statement('CREATE INDEX idx_payments_order ON payments(orders_id)');
        }
        if (!$this->indexExists('payments', 'idx_payments_deleted')) {
            DB::statement('CREATE INDEX idx_payments_deleted ON payments(is_deleted)');
        }
        if (!$this->indexExists('payments', 'idx_payments_date_deleted')) {
            DB::statement('CREATE INDEX idx_payments_date_deleted ON payments(date, is_deleted)');
        }

        // ====== TREATMENTS TABLE ======
        if (!$this->indexExists('treatments', 'idx_treatments_date_start')) {
            DB::statement('CREATE INDEX idx_treatments_date_start ON treatments(date_start)');
        }
        if (!$this->indexExists('treatments', 'idx_treatments_status')) {
            DB::statement('CREATE INDEX idx_treatments_status ON treatments(status)');
        }
        if (!$this->indexExists('treatments', 'idx_treatments_deleted')) {
            DB::statement('CREATE INDEX idx_treatments_deleted ON treatments(is_deleted)');
        }

        // ====== AD_CAMPAIGNS TABLE ======
        if (Schema::hasTable('ad_campaigns')) {
            if (!$this->indexExists('ad_campaigns', 'idx_ad_campaigns_date')) {
                DB::statement('CREATE INDEX idx_ad_campaigns_date ON ad_campaigns(date)');
            }
            if (!$this->indexExists('ad_campaigns', 'idx_ad_campaigns_platform')) {
                DB::statement('CREATE INDEX idx_ad_campaigns_platform ON ad_campaigns(platform)');
            }
        }

        // ====== EXPENSES TABLE ======
        if (!$this->indexExists('expenses', 'idx_expenses_date')) {
            DB::statement('CREATE INDEX idx_expenses_date ON expenses(date)');
        }
        if (!$this->indexExists('expenses', 'idx_expenses_deleted')) {
            DB::statement('CREATE INDEX idx_expenses_deleted ON expenses(is_deleted)');
        }

        // ====== EXPENSE_OPERATIONALS TABLE ======
        if (Schema::hasTable('expense_operationals')) {
            if (!$this->indexExists('expense_operationals', 'idx_expense_op_deleted')) {
                DB::statement('CREATE INDEX idx_expense_op_deleted ON expense_operationals(is_deleted)');
            }
        }

        // ====== CUSTOMERS TABLE ======
        if (!$this->indexExists('customers', 'idx_customers_phone')) {
            DB::statement('CREATE INDEX idx_customers_phone ON customers(phone)');
        }
        if (!$this->indexExists('customers', 'idx_customers_deleted')) {
            DB::statement('CREATE INDEX idx_customers_deleted ON customers(is_deleted)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes safely
        $this->dropIndexIfExists('orders', 'idx_orders_date_status_deleted');
        $this->dropIndexIfExists('orders', 'idx_orders_date');
        $this->dropIndexIfExists('orders', 'idx_orders_status');
        $this->dropIndexIfExists('orders', 'idx_orders_branch');
        $this->dropIndexIfExists('orders', 'idx_orders_deleted');

        $this->dropIndexIfExists('payments', 'idx_payments_date_deleted');
        $this->dropIndexIfExists('payments', 'idx_payments_date');
        $this->dropIndexIfExists('payments', 'idx_payments_order');
        $this->dropIndexIfExists('payments', 'idx_payments_deleted');

        $this->dropIndexIfExists('treatments', 'idx_treatments_date_start');
        $this->dropIndexIfExists('treatments', 'idx_treatments_status');
        $this->dropIndexIfExists('treatments', 'idx_treatments_deleted');

        if (Schema::hasTable('ad_campaigns')) {
            $this->dropIndexIfExists('ad_campaigns', 'idx_ad_campaigns_date');
            $this->dropIndexIfExists('ad_campaigns', 'idx_ad_campaigns_platform');
        }

        $this->dropIndexIfExists('expenses', 'idx_expenses_date');
        $this->dropIndexIfExists('expenses', 'idx_expenses_deleted');

        if (Schema::hasTable('expense_operationals')) {
            $this->dropIndexIfExists('expense_operationals', 'idx_expense_op_deleted');
        }

        $this->dropIndexIfExists('customers', 'idx_customers_phone');
        $this->dropIndexIfExists('customers', 'idx_customers_deleted');
    }

    /**
     * Check if index exists (MySQL compatible)
     */
    private function indexExists(string $table, string $index): bool
    {
        $result = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]);
        return !empty($result);
    }

    /**
     * Drop index if exists (safe, MySQL compatible)
     */
    private function dropIndexIfExists(string $table, string $index): void
    {
        if ($this->indexExists($table, $index)) {
            DB::statement("DROP INDEX {$index} ON {$table}");
        }
    }
};
