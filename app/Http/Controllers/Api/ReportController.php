<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Models\ServiceHpp;
use App\Models\Treatment;
use App\Models\Customer;
use App\Models\AdCampaign;
use App\Services\ReportCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * GET /api/reports/sales
     * Laporan Penjualan/Omzet
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function sales(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
        ];

        $data = ReportCacheService::remember(
            'sales',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];

        // Build query
        $query = Order::query()
            ->where('is_deleted', 0)
            ->whereIn('status', [1, 2]); // Process or Done

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }

        if ($branchId) {
            $query->where('projects_id', $branchId);
        }

        // Summary statistics
        $totalOrders = (clone $query)->count();
        $totalRevenue = (clone $query)->sum('total');
        $averageOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

        // Daily sales data
        $dailySales = (clone $query)
            ->select(
                DB::raw('FROM_UNIXTIME(date, "%Y-%m-%d") as sale_date'),
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('SUM(total) as total_revenue')
            )
            ->groupBy('sale_date')
            ->orderBy('sale_date', 'desc')
            ->get();

        // Status breakdown
        $statusBreakdown = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as revenue'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                return [
                    'status' => $item->status,
                    'status_label' => $this->getOrderStatusLabel($item->status),
                    'count' => $item->count,
                    'revenue' => $item->revenue,
                    ];
                });

                return [
                    'summary' => [
                        'total_orders' => $totalOrders,
                        'total_revenue' => $totalRevenue,
                        'average_order_value' => round($averageOrderValue, 2),
                    ],
                    'daily_sales' => $dailySales,
                    'status_breakdown' => $statusBreakdown,
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/receivables
     * Laporan Piutang/Kredit
     *
     * ⚡ CACHED: 15 minutes (QUICK_REPORT_TTL)
     */
    public function receivables(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status'    => ['nullable', 'in:unpaid,partial'],
            'page'       => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
            'status' => $request->input('status'),
            'page' => $request->input('page', 1),
            'per_page' => $request->input('per_page', 50),
        ];

        $data = ReportCacheService::remember(
            'receivables',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];
                $status = $params['status'];
                $page = $params['page'];
                $perPage = $params['per_page'];

        // Get orders with unpaid or partial payments
        $query = Order::query()
            ->with(['customer', 'project'])
            ->where('is_deleted', 0)
            ->whereIn('status', [1, 2]); // Process or Done

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }
        if ($branchId) {
            $query->where('projects_id', $branchId);
        }

        $allOrders = $query->get()->filter(function ($order) use ($status) {
            $totalPaid = Payment::where('orders_id', $order->id)
                ->where('is_deleted', 0)
                ->sum('total');

            $credit = $order->total - $totalPaid;

            // Filter by status
            if ($status === 'unpaid' && $totalPaid > 0) {
                return false;
            }
            if ($status === 'partial' && ($totalPaid == 0 || $credit == 0)) {
                return false;
            }

            return $credit > 0;
        })->map(function ($order) {
            $totalPaid = Payment::where('orders_id', $order->id)
                ->where('is_deleted', 0)
                ->sum('total');

            $credit = $order->total - $totalPaid;
            $dueDate = $order->date + (3 * 86400); // +3 days
            $daysOverdue = $dueDate < time() ? floor((time() - $dueDate) / 86400) : 0;

            return [
                'id' => $order->id,
                'code' => $order->code,
                'date' => $order->date,
                'customer_name' => $order->customer?->name ?? '-',
                'customer_phone' => $order->customer?->phone ?? '-',
                'branch_name' => $order->project?->name ?? '-',
                'total' => $order->total,
                'total_paid' => $totalPaid,
                'credit' => $credit,
                'due_date' => $dueDate,
                'days_overdue' => $daysOverdue,
                'status' => $totalPaid == 0 ? 'unpaid' : 'partial',
            ];
        })->values();

        // Summary
        $totalCredit = $allOrders->sum('credit');
        $totalOrders = $allOrders->count();
        $overdueOrders = $allOrders->filter(fn($o) => $o['days_overdue'] > 0)->count();

        // Paginate
        $offset = ($page - 1) * $perPage;
                $orders = $allOrders->slice($offset, $perPage)->values();
                $totalPages = ceil($totalOrders / $perPage);

                return [
                    'summary' => [
                        'total_credit' => $totalCredit,
                        'total_orders' => $totalOrders,
                        'overdue_orders' => $overdueOrders,
                    ],
                    'data' => $orders,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => $totalOrders,
                        'total_pages' => $totalPages,
                    ],
                ];
            },
            ReportCacheService::QUICK_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/payments
     * Laporan Pembayaran
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function payments(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
            'page'       => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
            'page' => $request->input('page', 1),
            'per_page' => $request->input('per_page', 50),
        ];

        $data = ReportCacheService::remember(
            'payments',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];
                $page = $params['page'];
                $perPage = $params['per_page'];

        // Build query for payments
        $query = Payment::query()
            ->with(['order.customer', 'order.project', 'user'])
            ->where('is_deleted', 0);

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }

        if ($branchId) {
            $query->whereHas('order', function ($q) use ($branchId) {
                $q->where('projects_id', $branchId);
            });
        }

        // Get all payments for summary and breakdowns
        $allPayments = (clone $query)->orderBy('date', 'desc')->get();

        // Summary statistics
        $totalPayments = $allPayments->count();
        $totalAmount = $allPayments->sum('total');
        $averagePayment = $totalPayments > 0 ? $totalAmount / $totalPayments : 0;

        // Daily payments
        $dailyPayments = $allPayments->groupBy(function ($payment) {
            return date('Y-m-d', $payment->date);
        })->map(function ($group, $date) {
            return [
                'payment_date' => $date,
                'total_payments' => $group->count(),
                'total_amount' => $group->sum('total'),
            ];
        })->values();

        // Payment method breakdown
        $methodBreakdown = $allPayments->groupBy('method')->map(function ($group, $method) {
            return [
                'method' => $method,
                'method_label' => $this->getPaymentMethodLabel($method),
                'count' => $group->count(),
                'total_amount' => $group->sum('total'),
            ];
        })->values();

        // Get paginated payments
        $offset = ($page - 1) * $perPage;
        $payments = $query
            ->orderBy('date', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        // Map payments data
        $paymentsData = $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'date' => $payment->date,
                'order_code' => $payment->order?->code ?? '-',
                'customer_name' => $payment->order?->customer?->name ?? '-',
                'customer_phone' => $payment->order?->customer?->phone ?? '-',
                'branch_name' => $payment->order?->project?->name ?? '-',
                'total' => $payment->total,
                'method' => $payment->method,
                'method_label' => $this->getPaymentMethodLabel($payment->method),
                'user_name' => $payment->user?->name ?? '-',
                'notes' => $payment->notes,
            ];
        });

                // Pagination meta
                $totalPages = ceil($totalPayments / $perPage);

                return [
                    'summary' => [
                        'total_payments' => $totalPayments,
                        'total_amount' => $totalAmount,
                        'average_payment' => round($averagePayment, 2),
                    ],
                    'daily_payments' => $dailyPayments,
                    'method_breakdown' => $methodBreakdown,
                    'data' => $paymentsData,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => $totalPayments,
                        'total_pages' => $totalPages,
                    ],
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/orders
     * Laporan Pesanan (Detail per Item/Service)
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function orders(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
            'status'     => ['nullable', 'in:0,1,2,3'],
            'page'       => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
            'status' => $request->input('status'),
            'page' => $request->input('page', 1),
            'per_page' => $request->input('per_page', 50),
        ];

        $data = ReportCacheService::remember(
            'orders',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];
                $status = $params['status'];
                $page = $params['page'];
                $perPage = $params['per_page'];

        // Build query for order items
        $query = OrderItem::query()
            ->join('orders', 'orders_items.orders_id', '=', 'orders.id')
            ->join('services', 'orders_items.services_id', '=', 'services.id')
            ->leftJoin('customers', 'orders.customers_id', '=', 'customers.id')
            ->leftJoin('projects', 'orders.projects_id', '=', 'projects.id')
            ->where('orders.is_deleted', 0)
            ->where('orders_items.is_deleted', 0);

        if ($startDate) {
            $query->where('orders.date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('orders.date', '<=', $endDate);
        }

        if ($branchId) {
            $query->where('orders.projects_id', $branchId);
        }

        if ($status !== null) {
            $query->where('orders.status', $status);
        }

        // Summary statistics
        $totalItems = (clone $query)->count();
        $totalRevenue = (clone $query)
            ->selectRaw('SUM(orders_items.price - orders_items.discount) as total')
            ->first()->total ?? 0;

        // Service breakdown
        $serviceBreakdown = (clone $query)
            ->select(
                'services.id',
                'services.name as service_name',
                DB::raw('COUNT(orders_items.id) as total_count'),
                DB::raw('SUM(orders_items.price - orders_items.discount) as total_revenue'),
                DB::raw('AVG(orders_items.price - orders_items.discount) as avg_price')
            )
            ->groupBy('services.id', 'services.name')
            ->orderBy('total_count', 'desc')
            ->get();

        // Status breakdown
        $statusBreakdown = Order::query()
            ->where('is_deleted', 0)
            ->when($startDate, fn($q) => $q->where('date', '>=', $startDate))
            ->when($endDate, fn($q) => $q->where('date', '<=', $endDate))
            ->when($branchId, fn($q) => $q->where('projects_id', $branchId))
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                return [
                    'status' => $item->status,
                    'status_label' => $this->getOrderStatusLabel($item->status),
                    'count' => $item->count,
                ];
            });

        // Get detailed items with pagination
        $offset = ($page - 1) * $perPage;
        $items = $query
            ->select(
                'orders_items.id',
                'orders.code as order_code',
                'orders.date as order_date',
                'orders.status as order_status',
                'customers.name as customer_name',
                'customers.phone as customer_phone',
                'projects.name as branch_name',
                'services.name as service_name',
                'orders_items.price',
                'orders_items.discount',
                DB::raw('(orders_items.price - orders_items.discount) as net_price')
            )
            ->orderBy('orders.date', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'order_code' => $item->order_code,
                    'order_date' => $item->order_date,
                    'order_status' => $this->getOrderStatusLabel($item->order_status),
                    'customer_name' => $item->customer_name ?? '-',
                    'customer_phone' => $item->customer_phone ?? '-',
                    'branch_name' => $item->branch_name ?? '-',
                    'service_name' => $item->service_name,
                    'price' => $item->price,
                    'discount' => $item->discount,
                    'net_price' => $item->net_price,
                ];
            });

                // Pagination meta
                $totalPages = ceil($totalItems / $perPage);

                return [
                    'summary' => [
                        'total_items' => $totalItems,
                        'total_revenue' => $totalRevenue,
                        'average_per_item' => $totalItems > 0 ? round($totalRevenue / $totalItems, 2) : 0,
                    ],
                    'service_breakdown' => $serviceBreakdown,
                    'status_breakdown' => $statusBreakdown,
                    'data' => $items,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => $totalItems,
                        'total_pages' => $totalPages,
                    ],
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/expenses
     * Laporan Pengeluaran (Umum + Operasional)
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function expenses(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
            'type'       => ['nullable', 'in:general,operational'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
            'type' => $request->input('type'),
        ];

        $data = ReportCacheService::remember(
            'expenses',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];
                $type = $params['type'];

        // General Expenses (expenses table)
        $generalQuery = \App\Models\Expense::query()
            ->with(['project', 'user'])
            ->where('is_deleted', 0);

        if ($startDate) {
            $generalQuery->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $generalQuery->where('date', '<=', $endDate);
        }
        if ($branchId) {
            $generalQuery->where('projects_id', $branchId);
        }

        $generalExpenses = ($type === 'operational') ? collect() : $generalQuery->orderBy('date', 'desc')->get();

        // Operational Expenses (expense_operationals table)
        $operationalQuery = \App\Models\ExpenseOperational::query()
            ->with(['project', 'user'])
            ->where('is_deleted', 0);

        if ($startDate) {
            $operationalQuery->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $operationalQuery->where('date', '<=', $endDate);
        }
        if ($branchId) {
            $operationalQuery->where('projects_id', $branchId);
        }

        $operationalExpenses = ($type === 'general') ? collect() : $operationalQuery->orderBy('date', 'desc')->get();

        // Summary
        $totalGeneral = $generalExpenses->sum('total');
        $totalOperational = $operationalExpenses->sum('total');
        $totalExpenses = $totalGeneral + $totalOperational;

        // Category breakdown for general expenses
        $categoryBreakdown = $generalExpenses->groupBy('category')->map(function ($group, $category) {
            return [
                'category' => $category ?: 'Tidak Berkategori',
                'count' => $group->count(),
                'total' => $group->sum('total'),
            ];
        })->values();

        // Daily expenses
        $allExpenses = $generalExpenses->merge($operationalExpenses);
        $dailyExpenses = $allExpenses->groupBy(function ($expense) {
            return date('Y-m-d', $expense->date);
        })->map(function ($group, $date) {
            return [
                'expense_date' => $date,
                'total_expenses' => $group->count(),
                'total_amount' => $group->sum('total'),
            ];
        })->sortByDesc('expense_date')->values();

        // Map general expenses
        $generalData = $generalExpenses->map(function ($expense) {
            return [
                'id' => $expense->id,
                'date' => $expense->date,
                'branch_name' => $expense->project?->name ?? '-',
                'category' => $expense->category ?: 'Tidak Berkategori',
                'description' => $expense->description,
                'total' => $expense->total,
                'user_name' => $expense->user?->name ?? '-',
                'type' => 'general',
            ];
        });

        // Map operational expenses
        $operationalData = $operationalExpenses->map(function ($expense) {
            return [
                'id' => $expense->id,
                'date' => $expense->date,
                'branch_name' => $expense->project?->name ?? '-',
                'category' => 'Operasional',
                'description' => $expense->description,
                'total' => $expense->total,
                'user_name' => $expense->user?->name ?? '-',
                'type' => 'operational',
            ];
        });

                // Combine and sort by date
                $combinedData = $generalData->merge($operationalData)->sortByDesc('date')->values();

                return [
                    'summary' => [
                        'total_expenses' => $totalExpenses,
                        'total_general' => $totalGeneral,
                        'total_operational' => $totalOperational,
                        'count_general' => $generalExpenses->count(),
                        'count_operational' => $operationalExpenses->count(),
                    ],
                    'category_breakdown' => $categoryBreakdown,
                    'daily_expenses' => $dailyExpenses,
                    'data' => $combinedData,
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/hpp
     * Laporan HPP (Harga Pokok Penjualan) per Service
     *
     * ⚡ CACHED: 6 hours (HEAVY_REPORT_TTL)
     */
    public function hpp(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
        ];

        $data = ReportCacheService::remember(
            'hpp',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];

        // Get all services with their sales data
        $servicesQuery = OrderItem::query()
            ->join('orders', 'orders_items.orders_id', '=', 'orders.id')
            ->join('services', 'orders_items.services_id', '=', 'services.id')
            ->where('orders.is_deleted', 0)
            ->where('orders_items.is_deleted', 0)
            ->whereIn('orders.status', [1, 2]); // Process or Done

        if ($startDate) {
            $servicesQuery->where('orders.date', '>=', $startDate);
        }
        if ($endDate) {
            $servicesQuery->where('orders.date', '<=', $endDate);
        }
        if ($branchId) {
            $servicesQuery->where('orders.projects_id', $branchId);
        }

        $servicesData = $servicesQuery
            ->select(
                'services.id as service_id',
                'services.name as service_name',
                DB::raw('COUNT(orders_items.id) as total_sold'),
                DB::raw('SUM(orders_items.price - orders_items.discount) as total_revenue'),
                DB::raw('AVG(orders_items.price - orders_items.discount) as avg_price')
            )
            ->groupBy('services.id', 'services.name')
            ->get();

        // Get HPP data for each service
        $hppData = $servicesData->map(function ($service) use ($branchId) {
            // Get total cost per usage from services_hpp
            $hppQuery = ServiceHpp::query()
                ->where('services_id', $service->service_id);

            if ($branchId) {
                $hppQuery->where('projects_id', $branchId);
            }

            $totalCostPerUsage = $hppQuery->sum('cost_per_usage');

            // Calculate margins
            $totalCogs = $totalCostPerUsage * $service->total_sold; // Cost of Goods Sold
            $grossProfit = $service->total_revenue - $totalCogs;
            $marginPercent = $service->total_revenue > 0
                ? ($grossProfit / $service->total_revenue) * 100
                : 0;

            return [
                'service_id' => $service->service_id,
                'service_name' => $service->service_name,
                'total_sold' => $service->total_sold,
                'total_revenue' => $service->total_revenue,
                'avg_price' => round($service->avg_price, 2),
                'hpp_per_unit' => $totalCostPerUsage,
                'total_cogs' => $totalCogs,
                'gross_profit' => $grossProfit,
                'margin_percent' => round($marginPercent, 2),
            ];
        })->sortByDesc('total_revenue')->values();

                // Summary
                $totalRevenue = $hppData->sum('total_revenue');
                $totalCogs = $hppData->sum('total_cogs');
                $totalGrossProfit = $totalRevenue - $totalCogs;
                $overallMargin = $totalRevenue > 0 ? ($totalGrossProfit / $totalRevenue) * 100 : 0;

                return [
                    'summary' => [
                        'total_revenue' => $totalRevenue,
                        'total_cogs' => $totalCogs,
                        'total_gross_profit' => $totalGrossProfit,
                        'overall_margin_percent' => round($overallMargin, 2),
                        'total_services' => $hppData->count(),
                    ],
                    'data' => $hppData,
                ];
            },
            ReportCacheService::HEAVY_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/profit-loss
     * Laporan Laba Rugi (Profit & Loss Statement)
     *
     * ⚡ CACHED: 6 hours (HEAVY_REPORT_TTL)
     */
    public function profitLoss(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
        ];

        $data = ReportCacheService::remember(
            'profit-loss',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];

        // 1. Calculate Revenue (from completed orders)
        $revenueQuery = Order::query()
            ->where('is_deleted', 0)
            ->whereIn('status', [1, 2]); // Process or Done

        if ($startDate) {
            $revenueQuery->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $revenueQuery->where('date', '<=', $endDate);
        }
        if ($branchId) {
            $revenueQuery->where('projects_id', $branchId);
        }

        $totalRevenue = $revenueQuery->sum('total');

        // 2. Calculate COGS (Cost of Goods Sold)
        $servicesQuery = OrderItem::query()
            ->join('orders', 'orders_items.orders_id', '=', 'orders.id')
            ->where('orders.is_deleted', 0)
            ->where('orders_items.is_deleted', 0)
            ->whereIn('orders.status', [1, 2]);

        if ($startDate) {
            $servicesQuery->where('orders.date', '>=', $startDate);
        }
        if ($endDate) {
            $servicesQuery->where('orders.date', '<=', $endDate);
        }
        if ($branchId) {
            $servicesQuery->where('orders.projects_id', $branchId);
        }

        $servicesData = $servicesQuery
            ->select(
                'orders_items.services_id',
                DB::raw('COUNT(orders_items.id) as total_sold')
            )
            ->groupBy('orders_items.services_id')
            ->get();

        $totalCogs = 0;
        foreach ($servicesData as $service) {
            $hppQuery = ServiceHpp::query()
                ->where('services_id', $service->services_id);
            if ($branchId) {
                $hppQuery->where('projects_id', $branchId);
            }
            $costPerUsage = $hppQuery->sum('cost_per_usage');
            $totalCogs += $costPerUsage * $service->total_sold;
        }

        // 3. Calculate Operating Expenses
        $generalExpensesQuery = \App\Models\Expense::query()
            ->where('is_deleted', 0);
        $operationalExpensesQuery = \App\Models\ExpenseOperational::query()
            ->where('is_deleted', 0);

        if ($startDate) {
            $generalExpensesQuery->where('date', '>=', $startDate);
            $operationalExpensesQuery->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $generalExpensesQuery->where('date', '<=', $endDate);
            $operationalExpensesQuery->where('date', '<=', $endDate);
        }
        if ($branchId) {
            $generalExpensesQuery->where('projects_id', $branchId);
            $operationalExpensesQuery->where('projects_id', $branchId);
        }

        $generalExpenses = $generalExpensesQuery->sum('total');
        $operationalExpenses = $operationalExpensesQuery->sum('total');
        $totalExpenses = $generalExpenses + $operationalExpenses;

        // 4. Calculate Profit
        $grossProfit = $totalRevenue - $totalCogs;
        $netProfit = $grossProfit - $totalExpenses;

        $grossMargin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;
        $netMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

        // 5. Monthly breakdown (if period > 1 month)
        $monthlyData = [];
        if ($startDate && $endDate) {
            $start = new \DateTime();
            $start->setTimestamp($startDate);
            $end = new \DateTime();
            $end->setTimestamp($endDate);

            $interval = $start->diff($end);
            if ($interval->days > 31) {
                // Get monthly breakdown
                $period = new \DatePeriod(
                    $start,
                    new \DateInterval('P1M'),
                    $end
                );

                foreach ($period as $month) {
                    $monthStart = $month->getTimestamp();
                    $monthEnd = (clone $month)->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();

                    $monthRevenue = (clone $revenueQuery)
                        ->where('date', '>=', $monthStart)
                        ->where('date', '<=', $monthEnd)
                        ->sum('total');

                    $monthExpenses = \App\Models\Expense::query()
                        ->where('is_deleted', 0)
                        ->where('date', '>=', $monthStart)
                        ->where('date', '<=', $monthEnd)
                        ->when($branchId, fn($q) => $q->where('projects_id', $branchId))
                        ->sum('total') +
                        \App\Models\ExpenseOperational::query()
                        ->where('is_deleted', 0)
                        ->where('date', '>=', $monthStart)
                        ->where('date', '<=', $monthEnd)
                        ->when($branchId, fn($q) => $q->where('projects_id', $branchId))
                        ->sum('total');

                    $monthlyData[] = [
                        'month' => $month->format('Y-m'),
                        'month_label' => $month->format('M Y'),
                        'revenue' => $monthRevenue,
                        'expenses' => $monthExpenses,
                        'profit' => $monthRevenue - $monthExpenses,
                    ];
                }
                }
            }

                return [
                    'summary' => [
                        'total_revenue' => $totalRevenue,
                        'total_cogs' => $totalCogs,
                        'gross_profit' => $grossProfit,
                        'gross_margin_percent' => round($grossMargin, 2),
                        'total_expenses' => $totalExpenses,
                        'general_expenses' => $generalExpenses,
                        'operational_expenses' => $operationalExpenses,
                        'net_profit' => $netProfit,
                        'net_margin_percent' => round($netMargin, 2),
                    ],
                    'monthly_data' => $monthlyData,
                ];
            },
            ReportCacheService::HEAVY_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/cash-flow
     * Laporan Arus Kas (Cash Flow)
     *
     * ⚡ CACHED: 6 hours (HEAVY_REPORT_TTL)
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
        ];

        $data = ReportCacheService::remember(
            'cash-flow',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];

        // 1. Cash In (from payments)
        $paymentsQuery = Payment::query()
            ->where('is_deleted', 0);

        if ($startDate) {
            $paymentsQuery->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $paymentsQuery->where('date', '<=', $endDate);
        }
        if ($branchId) {
            $paymentsQuery->whereHas('order', function ($q) use ($branchId) {
                $q->where('projects_id', $branchId);
            });
        }

        $totalCashIn = $paymentsQuery->sum('total');
        $cashInCount = (clone $paymentsQuery)->count();

        // Payment method breakdown
        $paymentMethods = (clone $paymentsQuery)
            ->select('method', DB::raw('SUM(total) as total'))
            ->groupBy('method')
            ->get()
            ->map(function ($item) {
                return [
                    'method' => $item->method ?: 'Tidak Diketahui',
                    'method_label' => $this->getPaymentMethodLabel($item->method),
                    'total' => $item->total,
                ];
            });

        // 2. Cash Out (from expenses)
        $generalExpensesQuery = \App\Models\Expense::query()
            ->where('is_deleted', 0);
        $operationalExpensesQuery = \App\Models\ExpenseOperational::query()
            ->where('is_deleted', 0);

        if ($startDate) {
            $generalExpensesQuery->where('date', '>=', $startDate);
            $operationalExpensesQuery->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $generalExpensesQuery->where('date', '<=', $endDate);
            $operationalExpensesQuery->where('date', '<=', $endDate);
        }
        if ($branchId) {
            $generalExpensesQuery->where('projects_id', $branchId);
            $operationalExpensesQuery->where('projects_id', $branchId);
        }

        $totalCashOut = $generalExpensesQuery->sum('total') + $operationalExpensesQuery->sum('total');
        $cashOutCount = $generalExpensesQuery->count() + $operationalExpensesQuery->count();

        // 3. Net Cash Flow
        $netCashFlow = $totalCashIn - $totalCashOut;

        // 4. Daily cash flow
        $dailyCashFlow = [];

        if ($startDate && $endDate) {
            // Get all dates with transactions
            $payments = Payment::query()
                ->where('is_deleted', 0)
                ->where('date', '>=', $startDate)
                ->where('date', '<=', $endDate)
                ->when($branchId, function ($q) use ($branchId) {
                    $q->whereHas('order', fn($qo) => $qo->where('projects_id', $branchId));
                })
                ->get();

            $expenses = \App\Models\Expense::query()
                ->where('is_deleted', 0)
                ->where('date', '>=', $startDate)
                ->where('date', '<=', $endDate)
                ->when($branchId, fn($q) => $q->where('projects_id', $branchId))
                ->get();

            $operationalExpenses = \App\Models\ExpenseOperational::query()
                ->where('is_deleted', 0)
                ->where('date', '>=', $startDate)
                ->where('date', '<=', $endDate)
                ->when($branchId, fn($q) => $q->where('projects_id', $branchId))
                ->get();

            // Group by date
            $dailyData = [];

            foreach ($payments as $payment) {
                $date = date('Y-m-d', $payment->date);
                if (!isset($dailyData[$date])) {
                    $dailyData[$date] = ['cash_in' => 0, 'cash_out' => 0];
                }
                $dailyData[$date]['cash_in'] += $payment->total;
            }

            foreach ($expenses as $expense) {
                $date = date('Y-m-d', $expense->date);
                if (!isset($dailyData[$date])) {
                    $dailyData[$date] = ['cash_in' => 0, 'cash_out' => 0];
                }
                $dailyData[$date]['cash_out'] += $expense->total;
            }

            foreach ($operationalExpenses as $expense) {
                $date = date('Y-m-d', $expense->date);
                if (!isset($dailyData[$date])) {
                    $dailyData[$date] = ['cash_in' => 0, 'cash_out' => 0];
                }
                $dailyData[$date]['cash_out'] += $expense->total;
            }

            // Convert to array and calculate running balance
            $runningBalance = 0;
            foreach ($dailyData as $date => $data) {
                $netFlow = $data['cash_in'] - $data['cash_out'];
                $runningBalance += $netFlow;

                $dailyCashFlow[] = [
                    'date' => $date,
                    'cash_in' => $data['cash_in'],
                    'cash_out' => $data['cash_out'],
                    'net_flow' => $netFlow,
                    'running_balance' => $runningBalance,
                ];
            }

            // Sort by date descending
            usort($dailyCashFlow, function ($a, $b) {
                return strcmp($b['date'], $a['date']);
                });
            }

                return [
                    'summary' => [
                        'total_cash_in' => $totalCashIn,
                        'cash_in_count' => $cashInCount,
                        'total_cash_out' => $totalCashOut,
                        'cash_out_count' => $cashOutCount,
                        'net_cash_flow' => $netCashFlow,
                    ],
                    'payment_methods' => $paymentMethods,
                    'daily_cash_flow' => $dailyCashFlow,
                ];
            },
            ReportCacheService::HEAVY_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/treatments
     * Laporan Pengerjaan/Treatment
     *
     * ⚡ CACHED: 15 minutes (QUICK_REPORT_TTL)
     */
    public function treatments(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
        ];

        $data = ReportCacheService::remember(
            'treatments',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];

        // Get treatments (is_deleted filter already applied via global scope)
        $query = Treatment::query()
            ->with([
                'orderItem.service',
                'orderItem.order.customer',
                'orderItem.order.project',
                'user' => function ($query) {
                    $query->withoutGlobalScopes(); // Load user without branch/deleted scope
                },
                'partnership'
            ]);

        if ($startDate) {
            $query->where('date_start', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('date_start', '<=', $endDate);
        }
        if ($branchId) {
            $query->whereHas('orderItem.order', fn($q) => $q->where('projects_id', $branchId));
        }

        $treatments = $query->get();

        // Calculate statistics
        $totalTreatments = $treatments->count();
        $completedTreatments = $treatments->where('status', 2)->count();
        $inProgressTreatments = $treatments->where('status', 1)->count();
        $waitingTreatments = $treatments->where('status', 0)->count();

        // Calculate average duration (for completed treatments)
        $completedWithDuration = $treatments->where('status', 2)->filter(function ($t) {
            return $t->date_start && $t->date_end;
        });

        $avgDuration = 0;
        if ($completedWithDuration->count() > 0) {
            $totalDuration = $completedWithDuration->sum(function ($t) {
                return $t->date_end - $t->date_start;
            });
            $avgDuration = round($totalDuration / $completedWithDuration->count() / 86400, 1); // in days
        }

        // Technician productivity - group by partnership (priority) or user
        $technicianStats = collect();

        // Group by partnerships (vendors) - PRIORITY
        // If partnerships_id is set, use vendor regardless of users_id
        $byPartnership = $treatments->filter(fn($t) => $t->partnerships_id !== null)
            ->groupBy('partnerships_id');
        foreach ($byPartnership as $partnershipId => $group) {
            $partnership = $group->first()->partnership;
            $completed = $group->where('status', 2)->count();
            $inProgress = $group->where('status', 1)->count();

            $technicianStats->push([
                'user_id' => null,
                'user_name' => ($partnership?->name ?? 'Mitra #' . $partnershipId) . ' (Vendor)',
                'type' => 'vendor',
                'total_treatments' => $group->count(),
                'completed' => $completed,
                'in_progress' => $inProgress,
                'completion_rate' => $group->count() > 0 ? round(($completed / $group->count()) * 100, 1) : 0,
            ]);
        }

        // Group by internal users (users_id) - only if partnerships_id is NULL
        $byUser = $treatments->filter(fn($t) => $t->partnerships_id === null && $t->users_id !== null)
            ->groupBy('users_id');
        foreach ($byUser as $userId => $group) {
            $user = $group->first()->user;
            $completed = $group->where('status', 2)->count();
            $inProgress = $group->where('status', 1)->count();

            $technicianStats->push([
                'user_id' => $user?->id,
                'user_name' => $user?->name ?? 'User #' . $userId,
                'type' => 'internal',
                'total_treatments' => $group->count(),
                'completed' => $completed,
                'in_progress' => $inProgress,
                'completion_rate' => $group->count() > 0 ? round(($completed / $group->count()) * 100, 1) : 0,
            ]);
        }

        // Sort by total treatments and convert to values
        $technicianStats = $technicianStats->sortByDesc('total_treatments')->values();

        // Overdue treatments (>14 days)
        $now = time();
        $overdueTreatments = $treatments->filter(function ($t) use ($now) {
            if ($t->status == 2) return false; // Skip completed
            $daysRunning = floor(($now - $t->date_start) / 86400);
                    return $daysRunning > 14;
                })->count();

                return [
                    'summary' => [
                        'total_treatments' => $totalTreatments,
                        'completed' => $completedTreatments,
                        'in_progress' => $inProgressTreatments,
                        'waiting' => $waitingTreatments,
                        'overdue' => $overdueTreatments,
                        'avg_duration_days' => $avgDuration,
                    ],
                    'technician_stats' => $technicianStats,
                ];
            },
            ReportCacheService::QUICK_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/customers
     * Laporan Pelanggan (Customer Analytics)
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function customers(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
        ];

        $data = ReportCacheService::remember(
            'customers',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];

        // Get customers with their order stats
        $customersQuery = Customer::query()
            ->with('orders')
            ->where('is_deleted', 0);

        $customers = $customersQuery->get()->map(function ($customer) use ($startDate, $endDate, $branchId) {
            $ordersQuery = $customer->orders()->where('is_deleted', 0);

            if ($startDate) $ordersQuery->where('date', '>=', $startDate);
            if ($endDate) $ordersQuery->where('date', '<=', $endDate);
            if ($branchId) $ordersQuery->where('projects_id', $branchId);

            $orders = $ordersQuery->get();
            $totalOrders = $orders->count();
            $totalSpent = $orders->sum('total');

            // Last order date
            $lastOrder = $orders->sortByDesc('date')->first();

            return [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'total_orders' => $totalOrders,
                'total_spent' => $totalSpent,
                'avg_order_value' => $totalOrders > 0 ? $totalSpent / $totalOrders : 0,
                'last_order_date' => $lastOrder?->date,
            ];
        })->filter(fn($c) => $c['total_orders'] > 0);

        // Sort by total spent desc
        $topCustomers = $customers->sortByDesc('total_spent')->take(20)->values();

        // Summary
        $totalCustomers = $customers->count();
        $totalRevenue = $customers->sum('total_spent');
        $avgOrderValue = $totalRevenue > 0 ? $totalRevenue / $customers->sum('total_orders') : 0;
                $repeatCustomers = $customers->filter(fn($c) => $c['total_orders'] > 1)->count();
                $repeatRate = $totalCustomers > 0 ? ($repeatCustomers / $totalCustomers) * 100 : 0;

                return [
                    'summary' => [
                        'total_customers' => $totalCustomers,
                        'repeat_customers' => $repeatCustomers,
                        'repeat_rate_percent' => round($repeatRate, 1),
                        'total_revenue' => $totalRevenue,
                        'avg_order_value' => round($avgOrderValue, 2),
                    ],
                    'top_customers' => $topCustomers,
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/top-services
     * Laporan Layanan Terlaris
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function topServices(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
            'limit'      => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
            'limit' => $request->input('limit', 10),
        ];

        $data = ReportCacheService::remember(
            'top-services',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];
                $limit = $params['limit'];

        // Build query for order items
        $query = OrderItem::query()
            ->join('orders', 'orders_items.orders_id', '=', 'orders.id')
            ->join('services', 'orders_items.services_id', '=', 'services.id')
            ->where('orders.is_deleted', 0)
            ->where('orders_items.is_deleted', 0)
            ->whereIn('orders.status', [1, 2]); // Process or Done

        if ($startDate) {
            $query->where('orders.date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('orders.date', '<=', $endDate);
        }

        if ($branchId) {
            $query->where('orders.projects_id', $branchId);
        }

        // Get top services
        $topServices = $query
            ->select(
                'services.id',
                'services.name as service_name',
                DB::raw('COUNT(orders_items.id) as total_count'),
                DB::raw('SUM(orders_items.price - orders_items.discount) as total_revenue'),
                DB::raw('AVG(orders_items.price - orders_items.discount) as avg_price')
            )
            ->groupBy('services.id', 'services.name')
            ->orderBy('total_count', 'desc')
            ->limit($limit)
            ->get();

                // Total summary
                $totalTransactions = $topServices->sum('total_count');
                $totalRevenue = $topServices->sum('total_revenue');

                return [
                    'summary' => [
                        'total_transactions' => $totalTransactions,
                        'total_revenue' => $totalRevenue,
                    ],
                    'data' => $topServices,
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * Helper: Get order status label
     */
    private function getOrderStatusLabel(int $status): string
    {
        return match($status) {
            0 => 'Pending',
            1 => 'Process',
            2 => 'Done',
            3 => 'Canceled',
            default => 'Unknown',
        };
    }

    /**
     * Helper: Get payment method label
     */
    private function getPaymentMethodLabel($method): string
    {
        return match($method) {
            'cash' => 'Tunai',
            'transfer' => 'Transfer Bank',
            'qris' => 'QRIS',
            'ewallet' => 'E-Wallet',
            default => 'Lainnya',
        };
    }

    /**
     * GET /api/reports/google-ads
     * Laporan Google Ads Campaign
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function googleAds(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
        ];

        $data = ReportCacheService::remember(
            'google-ads',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];

        // Get Google Ads campaigns
        $query = AdCampaign::query()
            ->with(['project', 'user'])
            ->where('is_deleted', 0)
            ->where('platform', 'google');

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }
        if ($branchId) {
            $query->where('projects_id', $branchId);
        }

        $campaigns = $query->orderBy('date', 'desc')->get();

        // Summary statistics
        $totalImpressions = $campaigns->sum('impressions');
        $totalClicks = $campaigns->sum('clicks');
        $totalCost = $campaigns->sum('cost');
        $totalConversions = $campaigns->sum('conversions');
        $totalRevenue = $campaigns->sum('conversion_value');

        $avgCTR = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) * 100 : 0;
        $avgCPC = $totalClicks > 0 ? $totalCost / $totalClicks : 0;
        $avgCPA = $totalConversions > 0 ? $totalCost / $totalConversions : 0;
        $totalROAS = $totalCost > 0 ? ($totalRevenue / $totalCost) : 0;

        // Campaign breakdown
        $campaignBreakdown = $campaigns->groupBy('campaign_name')->map(function ($group, $name) {
            $impressions = $group->sum('impressions');
            $clicks = $group->sum('clicks');
            $cost = $group->sum('cost');
            $conversions = $group->sum('conversions');
            $revenue = $group->sum('conversion_value');

            return [
                'campaign_name' => $name,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'cost' => $cost,
                'conversions' => $conversions,
                'revenue' => $revenue,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0,
                'cpc' => $clicks > 0 ? round($cost / $clicks, 2) : 0,
                'cpa' => $conversions > 0 ? round($cost / $conversions, 2) : 0,
                'roas' => $cost > 0 ? round($revenue / $cost, 2) : 0,
            ];
        })->sortByDesc('cost')->values();

        // Daily performance
        $dailyPerformance = $campaigns->groupBy(function ($campaign) {
            return date('Y-m-d', $campaign->date);
        })->map(function ($group, $date) {
            return [
                'date' => $date,
                'impressions' => $group->sum('impressions'),
                'clicks' => $group->sum('clicks'),
                'cost' => $group->sum('cost'),
                'conversions' => $group->sum('conversions'),
                'revenue' => $group->sum('conversion_value'),
            ];
        })->sortByDesc('date')->values();

        // Map campaigns data
        $campaignsData = $campaigns->map(function ($campaign) {
            return [
                'id' => $campaign->id,
                'date' => $campaign->date,
                'campaign_name' => $campaign->campaign_name,
                'campaign_id' => $campaign->campaign_id,
                'impressions' => $campaign->impressions,
                'clicks' => $campaign->clicks,
                'cost' => $campaign->cost,
                'conversions' => $campaign->conversions,
                'revenue' => $campaign->conversion_value,
                'ctr' => $campaign->ctr,
                'cpc' => $campaign->cpc,
                'cpa' => $campaign->cpa,
                'roas' => $campaign->roas,
                'branch_name' => $campaign->project?->name ?? '-',
                'notes' => $campaign->notes,
                ];
            });

                return [
                    'summary' => [
                        'total_impressions' => $totalImpressions,
                        'total_clicks' => $totalClicks,
                        'total_cost' => $totalCost,
                        'total_conversions' => $totalConversions,
                        'total_revenue' => $totalRevenue,
                        'avg_ctr' => round($avgCTR, 2),
                        'avg_cpc' => round($avgCPC, 2),
                        'avg_cpa' => round($avgCPA, 2),
                        'total_roas' => round($totalROAS, 2),
                    ],
                    'campaign_breakdown' => $campaignBreakdown,
                    'daily_performance' => $dailyPerformance,
                    'data' => $campaignsData,
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/attendance
     * Laporan Absensi Karyawan
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function attendance(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
            'user_id'    => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
            'user_id' => $request->input('user_id'),
        ];

        $data = ReportCacheService::remember(
            'attendance',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];
                $userId = $params['user_id'];

        // Get all attendances in date range
        $query = \App\Models\Attendance::query()
            ->with('user')
            ->where('is_deleted', 0);

        if ($startDate) {
            $query->where('clock', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('clock', '<=', $endDate);
        }
        if ($branchId) {
            $query->where('projects_id', $branchId);
        }
        if ($userId) {
            $query->where('users_id', $userId);
        }

        $attendances = $query->orderBy('clock', 'desc')->get();

        // Group by date and user
        $grouped = $attendances->groupBy(function ($item) {
            return date('Y-m-d', $item->clock) . '-' . $item->users_id;
        })->map(function ($group) {
            $clockIn = $group->where('type', 0)->first();
            $clockOut = $group->where('type', 1)->first();
            $duration = ($clockIn && $clockOut) ? $clockOut->clock - $clockIn->clock : null;

            // Calculate late (if clock in > 08:00)
            $isLate = false;
            if ($clockIn) {
                $clockInTime = date('H:i', $clockIn->clock);
                $isLate = $clockInTime > '08:00';
            }

            // Calculate early departure (if clock out < 17:00)
            $isEarlyDeparture = false;
            if ($clockOut) {
                $clockOutTime = date('H:i', $clockOut->clock);
                $isEarlyDeparture = $clockOutTime < '17:00';
            }

            return [
                'date' => date('Y-m-d', $group->first()->clock),
                'user_id' => $group->first()->users_id,
                'user_name' => $group->first()->user?->name,
                'clock_in' => $clockIn ? [
                    'time' => $clockIn->clock,
                    'is_wfa' => $clockIn->is_wfa,
                ] : null,
                'clock_out' => $clockOut ? [
                    'time' => $clockOut->clock,
                    'is_wfa' => $clockOut->is_wfa,
                ] : null,
                'duration' => $duration,
                'is_late' => $isLate,
                'is_early_departure' => $isEarlyDeparture,
            ];
        })->values();

        // Get absences (izin/sakit/cuti) in same period
        $absencesQuery = \App\Models\AttendanceAbsence::query()
            ->with('user')
            ->where('is_deleted', 0)
            ->where('is_approval', 1); // Only approved

        if ($startDate) {
            $absencesQuery->where('date_start', '<=', $endDate ?? time());
        }
        if ($endDate) {
            $absencesQuery->where('date_end', '>=', $startDate ?? 0);
        }
        if ($branchId) {
            $absencesQuery->where('projects_id', $branchId);
        }
        if ($userId) {
            $absencesQuery->where('users_id', $userId);
        }

        $absences = $absencesQuery->get();

        // Calculate summary statistics
        $totalDays = $grouped->count();
        $totalLate = $grouped->where('is_late', true)->count();
        $totalEarlyDeparture = $grouped->where('is_early_departure', true)->count();
        $totalAbsences = $absences->sum('total_days');

        // Calculate total working hours
        $totalWorkingSeconds = $grouped->whereNotNull('duration')->sum('duration');
        $totalWorkingHours = round($totalWorkingSeconds / 3600, 1);
        $avgWorkingHours = $totalDays > 0 ? round($totalWorkingHours / $totalDays, 1) : 0;

        // User statistics (group by user)
        $userStats = $grouped->groupBy('user_id')->map(function ($userGroup, $userId) use ($absences) {
            $userName = $userGroup->first()['user_name'];
            $totalPresent = $userGroup->count();
            $totalLate = $userGroup->where('is_late', true)->count();
            $totalEarlyDeparture = $userGroup->where('is_early_departure', true)->count();
            $totalHours = round($userGroup->whereNotNull('duration')->sum('duration') / 3600, 1);

            // Count absences for this user
            $userAbsences = $absences->where('users_id', $userId)->sum('total_days');

            return [
                'user_id' => $userId,
                'user_name' => $userName,
                'total_present' => $totalPresent,
                'total_late' => $totalLate,
                'total_early_departure' => $totalEarlyDeparture,
                'total_absences' => $userAbsences,
                'total_hours' => $totalHours,
                'avg_hours_per_day' => $totalPresent > 0 ? round($totalHours / $totalPresent, 1) : 0,
                'attendance_rate' => $totalPresent > 0 ? round(($totalPresent / ($totalPresent + $userAbsences)) * 100, 1) : 0,
            ];
        })->sortByDesc('total_present')->values();

        // Absence breakdown by type
        $absenceBreakdown = $absences->groupBy('type')->map(function ($group, $type) {
            return [
                'type' => $type,
                'type_label' => $this->getAbsenceTypeLabel($type),
                'count' => $group->count(),
                'total_days' => $group->sum('total_days'),
                ];
            })->values();

                return [
                    'summary' => [
                        'total_days' => $totalDays,
                        'total_late' => $totalLate,
                        'total_early_departure' => $totalEarlyDeparture,
                        'total_absences' => $totalAbsences,
                        'total_working_hours' => $totalWorkingHours,
                        'avg_working_hours_per_day' => $avgWorkingHours,
                        'total_employees' => $userStats->count(),
                    ],
                    'user_stats' => $userStats,
                    'absence_breakdown' => $absenceBreakdown,
                    'daily_attendance' => $grouped,
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * Helper: Get absence type label
     */
    private function getAbsenceTypeLabel(int $type): string
    {
        return match($type) {
            0 => 'Sakit',
            1 => 'Izin',
            2 => 'Cuti',
            default => 'Unknown',
        };
    }

    /**
     * GET /api/reports/daily-notes
     * Laporan Catatan Harian
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function dailyNotes(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
            'user_id'    => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
            'user_id' => $request->input('user_id'),
        ];

        $data = ReportCacheService::remember(
            'daily-notes',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];
                $userId = $params['user_id'];

        $query = \App\Models\DailyNote::query()
            ->with(['user', 'project'])
            ->where('is_deleted', 0);

        if ($startDate) $query->where('date', '>=', $startDate);
        if ($endDate) $query->where('date', '<=', $endDate);
        if ($branchId) $query->where('projects_id', $branchId);
        if ($userId) $query->where('users_id', $userId);

        $notes = $query->orderBy('date', 'desc')->get();

        // Summary statistics
        $totalNotes = $notes->count();
        $totalUsers = $notes->pluck('users_id')->unique()->count();
        $avgNoteLength = $notes->avg(fn($n) => strlen($n->title ?? $n->note));

        // User statistics
        $userStats = $notes->groupBy('users_id')->map(function ($userNotes) {
            $user = $userNotes->first()->user;
            return [
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'total_notes' => $userNotes->count(),
                'avg_note_length' => round($userNotes->avg(fn($n) => strlen($n->title ?? ''))),
                'last_note_date' => $userNotes->max('date'),
            ];
        })->sortByDesc('total_notes')->values();

        // Daily breakdown
        $dailyBreakdown = $notes->groupBy(function ($note) {
            return date('Y-m-d', $note->date);
        })->map(function ($group, $date) {
            return [
                'date' => $date,
                'total_notes' => $group->count(),
                'users_count' => $group->pluck('users_id')->unique()->count(),
            ];
        })->sortByDesc('date')->values();

        $data = $notes->map(function ($note) {
            return [
                'id' => $note->id,
                'user_name' => $note->user?->name,
                'branch_name' => $note->project?->name,
                'date' => $note->date,
                'note' => $note->title,
                'activities' => $note->description,
                ];
            });

                return [
                    'summary' => [
                        'total_notes' => $totalNotes,
                        'total_users' => $totalUsers,
                        'avg_note_length' => round($avgNoteLength),
                    ],
                    'user_stats' => $userStats,
                    'daily_breakdown' => $dailyBreakdown,
                    'data' => $data,
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/daily-notes-matrix
     * Laporan Catatan Harian (Format Matrix: User x Tanggal)
     * Mirip dengan app lama
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function dailyNotesMatrix(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2020'],
            'branch_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $params = [
            'month' => $request->input('month'),
            'year' => $request->input('year'),
            'branch_id' => $request->input('branch_id'),
        ];

        $data = ReportCacheService::remember(
            'daily-notes-matrix',
            $params,
            function() use ($params) {
                $month = $params['month'];
                $year = $params['year'];
                $branchId = $params['branch_id'];

        // Calculate start and end dates
        $startDate = mktime(0, 0, 0, $month, 1, $year);
        $daysInMonth = date('t', $startDate);
        $endDate = mktime(23, 59, 59, $month, $daysInMonth, $year);

        // Get all dates in the month
        $dates = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dates[] = $day;
        }

        // Get all users except Admin Super (match legacy: roles_id NOT IN (1))
        $userQuery = \App\Models\User::query()
            ->withoutGlobalScope('active') // Remove global scope to avoid ambiguous is_deleted
            ->select('users.*')
            ->where('users.is_deleted', 0)
            ->where('users.roles_id', '!=', 1); // Exclude Admin Super (id = 1)

        if ($branchId) {
            $userQuery->where('users.projects_id', $branchId);
        }

        $users = $userQuery
            ->with('role')
            ->orderBy('users.name')
            ->get();

        // Get all notes for the period
        $notesQuery = \App\Models\DailyNote::query()
            ->where('is_deleted', 0)
            ->where('date', '>=', $startDate)
            ->where('date', '<=', $endDate);

        if ($branchId) {
            $notesQuery->where('projects_id', $branchId);
        }

        $notes = $notesQuery->get();

        // Group notes by user and date
        $notesByUser = $notes->groupBy('users_id');

        // Build matrix data
        $usersData = $users->map(function ($user) use ($notesByUser, $dates, $month, $year) {
            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role?->name ?? 'Staff',
                'data' => [],
            ];

            // For each date in the month
            foreach ($dates as $day) {
                $dateTimestamp = mktime(0, 0, 0, $month, $day, $year);

                // Find notes for this user on this date
                $userNotes = $notesByUser->get($user->id, collect());
                $dayNotes = $userNotes->filter(function ($note) use ($dateTimestamp) {
                    $noteDate = date('Y-m-d', $note->date);
                    $targetDate = date('Y-m-d', $dateTimestamp);
                    return $noteDate === $targetDate;
                })->values();

                // Add notes for this day (can be empty array or contain notes)
                $userData['data'][] = $dayNotes->map(function ($note) {
                    return [
                        'id' => $note->id,
                        'title' => $note->title,
                        'description' => $note->description,
                    ];
                })->toArray();
            }

            return $userData;
        });

                return [
                    'users' => $usersData->values(),
                    'dates' => $dates,
                    'month' => $month,
                    'year' => $year,
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * GET /api/reports/performance
     * Laporan Performa Karyawan
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function performance(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
            'user_id'    => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
            'user_id' => $request->input('user_id'),
        ];

        $data = ReportCacheService::remember(
            'performance',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];
                $userId = $params['user_id'];

        // Get all users with role relationship
        $usersQuery = \App\Models\User::with('role')->where('is_deleted', 0);
        if ($branchId) $usersQuery->where('projects_id', $branchId);
        if ($userId) $usersQuery->where('id', $userId);
        $users = $usersQuery->get();

        $performanceData = $users->map(function ($user) use ($startDate, $endDate) {
            // Attendance stats
            $attendanceQuery = \App\Models\Attendance::where('users_id', $user->id)
                ->where('is_deleted', 0);
            if ($startDate) $attendanceQuery->where('clock', '>=', $startDate);
            if ($endDate) $attendanceQuery->where('clock', '<=', $endDate);

            $attendances = $attendanceQuery->get();
            $grouped = $attendances->groupBy(function ($item) {
                return date('Y-m-d', $item->clock) . '-' . $item->users_id;
            });

            $totalPresent = $grouped->count();
            $totalLate = $grouped->filter(function ($group) {
                $clockIn = $group->where('type', 0)->first();
                if ($clockIn) {
                    return date('H:i', $clockIn->clock) > '08:00';
                }
                return false;
            })->count();

            $totalWorkingSeconds = $grouped->filter(function ($group) {
                $clockIn = $group->where('type', 0)->first();
                $clockOut = $group->where('type', 1)->first();
                return $clockIn && $clockOut;
            })->sum(function ($group) {
                $clockIn = $group->where('type', 0)->first();
                $clockOut = $group->where('type', 1)->first();
                return $clockOut->clock - $clockIn->clock;
            });
            $totalWorkingHours = round($totalWorkingSeconds / 3600, 1);

            // Treatment stats
            $treatmentsQuery = Treatment::where('users_id', $user->id);
            if ($startDate) $treatmentsQuery->where('date_start', '>=', $startDate);
            if ($endDate) $treatmentsQuery->where('date_start', '<=', $endDate);

            $treatments = $treatmentsQuery->get();
            $completedTreatments = $treatments->where('status', 2)->count();
            $totalTreatments = $treatments->count();

            // Daily notes stats
            $notesQuery = \App\Models\DailyNote::where('users_id', $user->id)
                ->where('is_deleted', 0);
            if ($startDate) $notesQuery->where('date', '>=', $startDate);
            if ($endDate) $notesQuery->where('date', '<=', $endDate);
            $totalNotes = $notesQuery->count();

            // Performance score (simple calculation)
            $attendanceScore = $totalPresent > 0 ? (($totalPresent - $totalLate) / $totalPresent) * 30 : 0;
            $treatmentScore = $totalTreatments > 0 ? ($completedTreatments / $totalTreatments) * 40 : 0;
            $notesScore = min($totalNotes * 3, 30); // Max 30 points (10 notes = full score)
            $performanceScore = round($attendanceScore + $treatmentScore + $notesScore, 1);

            return [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'role' => $user->role->name ?? 'Staff',
                'total_present' => $totalPresent,
                'total_late' => $totalLate,
                'total_working_hours' => $totalWorkingHours,
                'total_treatments' => $totalTreatments,
                'completed_treatments' => $completedTreatments,
                'completion_rate' => $totalTreatments > 0 ? round(($completedTreatments / $totalTreatments) * 100, 1) : 0,
                'total_notes' => $totalNotes,
                'performance_score' => $performanceScore,
                'grade' => $this->getPerformanceGrade($performanceScore),
            ];
        })->sortByDesc('performance_score')->values();

        // Overall summary
        $avgScore = $performanceData->avg('performance_score');
                $topPerformers = $performanceData->filter(fn($p) => $p['performance_score'] >= 80)->count();
                $needsImprovement = $performanceData->filter(fn($p) => $p['performance_score'] < 60)->count();

                return [
                    'summary' => [
                        'total_employees' => $performanceData->count(),
                        'avg_performance_score' => round($avgScore, 1),
                        'top_performers' => $topPerformers,
                        'needs_improvement' => $needsImprovement,
                    ],
                    'data' => $performanceData,
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }

    /**
     * Helper: Get performance grade
     */
    private function getPerformanceGrade(float $score): string
    {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'E';
    }

    /**
     * GET /api/reports/meta-ads
     * Laporan Meta Ads (Facebook & Instagram) Campaign
     *
     * ⚡ CACHED: 1 hour (STANDARD_REPORT_TTL)
     */
    public function metaAds(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'integer'],
            'end_date'   => ['nullable', 'integer'],
            'branch_id'  => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $params = [
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'branch_id' => $request->input('branch_id'),
        ];

        $data = ReportCacheService::remember(
            'meta-ads',
            $params,
            function() use ($params) {
                $startDate = $params['start_date'];
                $endDate = $params['end_date'];
                $branchId = $params['branch_id'];

        // Get Meta Ads campaigns
        $query = AdCampaign::query()
            ->with(['project', 'user'])
            ->where('is_deleted', 0)
            ->where('platform', 'meta');

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }
        if ($branchId) {
            $query->where('projects_id', $branchId);
        }

        $campaigns = $query->orderBy('date', 'desc')->get();

        // Summary statistics
        $totalImpressions = $campaigns->sum('impressions');
        $totalClicks = $campaigns->sum('clicks');
        $totalCost = $campaigns->sum('cost');
        $totalConversions = $campaigns->sum('conversions');
        $totalRevenue = $campaigns->sum('conversion_value');

        $avgCTR = $totalImpressions > 0 ? ($totalClicks / $totalImpressions) * 100 : 0;
        $avgCPC = $totalClicks > 0 ? $totalCost / $totalClicks : 0;
        $avgCPA = $totalConversions > 0 ? $totalCost / $totalConversions : 0;
        $totalROAS = $totalCost > 0 ? ($totalRevenue / $totalCost) : 0;

        // Campaign breakdown
        $campaignBreakdown = $campaigns->groupBy('campaign_name')->map(function ($group, $name) {
            $impressions = $group->sum('impressions');
            $clicks = $group->sum('clicks');
            $cost = $group->sum('cost');
            $conversions = $group->sum('conversions');
            $revenue = $group->sum('conversion_value');

            return [
                'campaign_name' => $name,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'cost' => $cost,
                'conversions' => $conversions,
                'revenue' => $revenue,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0,
                'cpc' => $clicks > 0 ? round($cost / $clicks, 2) : 0,
                'cpa' => $conversions > 0 ? round($cost / $conversions, 2) : 0,
                'roas' => $cost > 0 ? round($revenue / $cost, 2) : 0,
            ];
        })->sortByDesc('cost')->values();

        // Daily performance
        $dailyPerformance = $campaigns->groupBy(function ($campaign) {
            return date('Y-m-d', $campaign->date);
        })->map(function ($group, $date) {
            return [
                'date' => $date,
                'impressions' => $group->sum('impressions'),
                'clicks' => $group->sum('clicks'),
                'cost' => $group->sum('cost'),
                'conversions' => $group->sum('conversions'),
                'revenue' => $group->sum('conversion_value'),
            ];
        })->sortByDesc('date')->values();

        // Map campaigns data
        $campaignsData = $campaigns->map(function ($campaign) {
            return [
                'id' => $campaign->id,
                'date' => $campaign->date,
                'campaign_name' => $campaign->campaign_name,
                'campaign_id' => $campaign->campaign_id,
                'impressions' => $campaign->impressions,
                'clicks' => $campaign->clicks,
                'cost' => $campaign->cost,
                'conversions' => $campaign->conversions,
                'revenue' => $campaign->conversion_value,
                'ctr' => $campaign->ctr,
                'cpc' => $campaign->cpc,
                'cpa' => $campaign->cpa,
                'roas' => $campaign->roas,
                'branch_name' => $campaign->project?->name ?? '-',
                'notes' => $campaign->notes,
                ];
            });

                return [
                    'summary' => [
                        'total_impressions' => $totalImpressions,
                        'total_clicks' => $totalClicks,
                        'total_cost' => $totalCost,
                        'total_conversions' => $totalConversions,
                        'total_revenue' => $totalRevenue,
                        'avg_ctr' => round($avgCTR, 2),
                        'avg_cpc' => round($avgCPC, 2),
                        'avg_cpa' => round($avgCPA, 2),
                        'total_roas' => round($totalROAS, 2),
                    ],
                    'campaign_breakdown' => $campaignBreakdown,
                    'daily_performance' => $dailyPerformance,
                    'data' => $campaignsData,
                ];
            },
            ReportCacheService::STANDARD_REPORT_TTL
        );

        return response()->json($data);
    }
}
