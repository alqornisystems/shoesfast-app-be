<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treatment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Summary stats for the admin dashboard.
     *
     * Scoped to the active branch automatically via the models' BranchScoped
     * global scope (Order/Treatment); Customer is global. Timestamps are unix
     * seconds. Soft-deleted rows are excluded by the models' notDeleted scope.
     */
    public function index(): JsonResponse
    {
        $startOfMonth = Carbon::now()->startOfMonth()->timestamp;
        $endOfMonth = Carbon::now()->endOfMonth()->timestamp;

        // Orders placed this month (any status)
        $ordersThisMonth = Order::whereBetween('date', [$startOfMonth, $endOfMonth])->count();

        // Revenue this month — order value for orders in process/done (mirrors the sales report)
        $revenueThisMonth = Order::whereIn('status', [1, 2])
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('total_price');

        // Treatments currently in progress (not done = 2, not cancelled = 3)
        $inProgressTreatments = Treatment::whereNotIn('status', [2, 3])->count();

        // Total registered customers
        $totalCustomers = Customer::count();

        return response()->json([
            'orders_this_month' => (int) $ordersThisMonth,
            'total_customers' => (int) $totalCustomers,
            'in_progress_treatments' => (int) $inProgressTreatments,
            'revenue_this_month' => (int) $revenueThisMonth,
        ]);
    }
}
