<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Treatment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Summary stats for the admin dashboard.
     *
     * Scoped to the active branch automatically via the models' BranchScoped
     * global scope (Order/Treatment/Expense/OrderItem); Customer is global.
     * Timestamps are unix seconds; soft-deleted rows excluded by notDeleted scope.
     */
    public function index(): JsonResponse
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth()->timestamp;
        $monthEnd = $now->copy()->endOfMonth()->timestamp;
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth()->timestamp;
        $lastMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth()->timestamp;
        $todayStart = $now->copy()->startOfDay()->timestamp;
        $todayEnd = $now->copy()->endOfDay()->timestamp;

        // --- Orders (count) ---
        $ordersThisMonth = Order::whereBetween('date', [$monthStart, $monthEnd])->count();
        $ordersLastMonth = Order::whereBetween('date', [$lastMonthStart, $lastMonthEnd])->count();
        $ordersToday = Order::whereBetween('date', [$todayStart, $todayEnd])->count();

        // --- Revenue (order value for orders in process/done — mirrors sales report) ---
        $revenueThisMonth = (int) Order::whereIn('status', [1, 2])
            ->whereBetween('date', [$monthStart, $monthEnd])->sum('total_price');
        $revenueLastMonth = (int) Order::whereIn('status', [1, 2])
            ->whereBetween('date', [$lastMonthStart, $lastMonthEnd])->sum('total_price');
        $revenueToday = (int) Order::whereIn('status', [1, 2])
            ->whereBetween('date', [$todayStart, $todayEnd])->sum('total_price');

        // --- Customers (global) ---
        $totalCustomers = Customer::count();
        $newCustomersThisMonth = Customer::whereBetween('created_at', [$monthStart, $monthEnd])->count();
        $newCustomersLastMonth = Customer::whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->count();

        // --- Treatments currently in progress (not done = 2, not cancelled = 3) ---
        $inProgressTreatments = Treatment::whereNotIn('status', [2, 3])->count();

        // --- Finance ---
        // Expenses this/last month (dated `expenses` only; operational costs have no txn date)
        $expensesThisMonth = (int) Expense::whereBetween('date', [$monthStart, $monthEnd])->sum('nominal');
        $expensesLastMonth = (int) Expense::whereBetween('date', [$lastMonthStart, $lastMonthEnd])->sum('nominal');

        // Outstanding receivables: sum of unpaid remainder over active orders
        $receivables = (int) Order::whereIn('status', [1, 2])
            ->select(DB::raw(
                'COALESCE(SUM(GREATEST(total_price - COALESCE((' .
                'SELECT SUM(nominal) FROM payments ' .
                'WHERE payments.orders_id = orders.id AND payments.is_deleted = 0' .
                '), 0), 0)), 0) as receivables'
            ))
            ->value('receivables');

        // Top services this month (by order-item count)
        $topServices = OrderItem::whereHas('order', function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('date', [$monthStart, $monthEnd]);
            })
            ->select('name', DB::raw('COUNT(*) as count'))
            ->groupBy('name')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?: '-',
                'count' => (int) $row->count,
            ]);

        return response()->json([
            'orders' => [
                'this_month' => (int) $ordersThisMonth,
                'last_month' => (int) $ordersLastMonth,
                'today' => (int) $ordersToday,
            ],
            'revenue' => [
                'this_month' => $revenueThisMonth,
                'last_month' => $revenueLastMonth,
                'today' => $revenueToday,
            ],
            'customers' => [
                'total' => (int) $totalCustomers,
                'new_this_month' => (int) $newCustomersThisMonth,
                'new_last_month' => (int) $newCustomersLastMonth,
            ],
            'treatments' => [
                'in_progress' => (int) $inProgressTreatments,
            ],
            'finance' => [
                'revenue_this_month' => $revenueThisMonth,
                'expenses_this_month' => $expensesThisMonth,
                'expenses_last_month' => $expensesLastMonth,
                'gross_profit' => $revenueThisMonth - $expensesThisMonth,
                'receivables' => $receivables,
            ],
            'top_services' => $topServices,
        ]);
    }
}
