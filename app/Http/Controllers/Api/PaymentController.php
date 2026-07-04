<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Order;
use App\Services\ReportCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Display a listing of payments (orders with payment info)
     */
    public function index(Request $request)
    {
        $query = Order::with(['customer', 'project'])
            ->where('total_price', '!=', 0)
            ->orderBy('date', 'DESC');

        // Search by order code or customer name
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($qCustomer) use ($search) {
                        $qCustomer->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Filter by payment status
        if ($request->has('status') && $request->status !== '') {
            $statuses = explode(',', $request->status);

            if (count($statuses) > 1) {
                // Multiple statuses (e.g., "unpaid,partial")
                $query->where(function ($q) use ($statuses) {
                    foreach ($statuses as $status) {
                        $status = trim($status);
                        if ($status === 'unpaid') {
                            $q->orWhereDoesntHave('payments');
                        } elseif ($status === 'partial') {
                            $q->orWhereRaw('total_price > (SELECT COALESCE(SUM(nominal), 0) FROM payments WHERE orders_id = orders.id AND is_deleted = 0)')
                              ->whereHas('payments');
                        } elseif ($status === 'paid') {
                            $q->orWhereRaw('total_price = (SELECT COALESCE(SUM(nominal), 0) FROM payments WHERE orders_id = orders.id AND is_deleted = 0)')
                              ->whereHas('payments');
                        }
                    }
                });
            } else {
                // Single status
                $status = $statuses[0];
                if ($status === 'paid') {
                    // Fully paid
                    $query->whereRaw('total_price = (SELECT COALESCE(SUM(nominal), 0) FROM payments WHERE orders_id = orders.id AND is_deleted = 0)')
                          ->whereHas('payments');
                } elseif ($status === 'unpaid') {
                    // No payment yet
                    $query->whereDoesntHave('payments');
                } elseif ($status === 'partial') {
                    // Partial payment (has payment but not full)
                    $query->whereRaw('total_price > (SELECT COALESCE(SUM(nominal), 0) FROM payments WHERE orders_id = orders.id AND is_deleted = 0)')
                          ->whereHas('payments');
                }
            }
        }

        $perPage = $request->get('per_page', 15);
        $orders = $query->paginate($perPage);

        // Transform data with payment info
        $orders->getCollection()->transform(function ($order) {
            $dueDate = strtotime(date('Y-m-d', strtotime(date('Y-m-d', $order->date) . ' +3 days')));

            // Get all payments for this order and sum them
            $totalPaid = Payment::where('orders_id', $order->id)->sum('nominal');

            // Get latest payment for display
            $payment = Payment::where('orders_id', $order->id)
                ->orderBy('date', 'DESC')
                ->first();
            $credit = $order->total_price - $totalPaid;

            // Calculate late days (only if unpaid and past due)
            $late = '-';
            if ($totalPaid === 0 && $dueDate < time()) {
                $lateDays = floor((time() - $dueDate) / 86400);
                $late = $lateDays . ' hari';
            }

            return [
                'id' => $order->id,
                'code' => $order->code,
                'date' => $order->date,
                'due_date' => $dueDate,
                'total_price' => $order->total_price,
                'total_paid' => $totalPaid,
                'credit' => $credit,
                'late' => $late,
                'payment_status' => $credit === 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid'),
                'customer' => [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                    'email' => $order->customer->email,
                    'address' => $order->customer->address,
                ],
                'payment' => $payment ? [
                    'id' => $payment->id,
                    'date' => $payment->date,
                    'nominal' => $payment->nominal,
                    'note' => $payment->note,
                    'photo' => $payment->photo,
                ] : null,
                'project_name' => $order->project ? $order->project->name : null,
                'created_at' => $order->created_at,
            ];
        });

        return response()->json($orders);
    }

    /**
     * Store or update payment
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'orders_id' => 'required|exists:orders,id',
            'date' => 'required|date',
            'nominal' => 'required|integer|min:1',
            'note' => 'nullable|string',
            'photo' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Check if order exists and get total price
            $order = Order::findOrFail($validated['orders_id']);

            // Handle photo upload (base64)
            $photoPath = null;
            if (isset($validated['photo']) && $validated['photo']) {
                $photoPath = $this->saveBase64Image($validated['photo'], 'payments');
            }

            // Always create new payment (support multiple payments/cicilan)
            $payment = Payment::create([
                'orders_id' => $validated['orders_id'],
                'date' => strtotime($validated['date']),
                'nominal' => $validated['nominal'],
                'note' => $validated['note'] ?? null,
                'photo' => $photoPath,
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'payments',
                'receivables',
                'cash-flow',
                'profit-loss',
            ]);

            return response()->json([
                'message' => 'Pembayaran berhasil disimpan',
                'data' => $payment->load('order.customer'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal menyimpan pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save base64 image to storage
     */
    private function saveBase64Image($base64String, $folder = 'payments')
    {
        // Check if it's a valid base64 image
        if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
            $base64String = substr($base64String, strpos($base64String, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, gif

            $base64String = str_replace(' ', '+', $base64String);
            $imageData = base64_decode($base64String);

            if ($imageData === false) {
                throw new \Exception('Base64 decode failed');
            }

            // Generate unique filename
            $fileName = uniqid() . '.' . $type;
            $directory = storage_path("app/public/{$folder}");

            // Create directory if not exists
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            $filePath = "{$directory}/{$fileName}";

            // Save file
            file_put_contents($filePath, $imageData);

            // Return relative path
            return "storage/{$folder}/{$fileName}";
        }

        return null;
    }

    /**
     * Get payment history by order ID
     */
    public function getByOrder($orderId)
    {
        $payments = Payment::with(['order.customer'])
            ->where('orders_id', $orderId)
            ->orderBy('date', 'DESC')
            ->get();

        if ($payments->isEmpty()) {
            return response()->json([]);
        }

        return response()->json($payments);
    }

    /**
     * Delete payment
     */
    public function destroy($id)
    {
        $payment = Payment::findOrFail($id);

        DB::beginTransaction();
        try {
            $payment->update([
                'is_deleted' => 1,
                'modified_by' => auth()->id(),
            ]);

            DB::commit();

            // Invalidate affected report caches
            ReportCacheService::invalidate([
                'payments',
                'receivables',
                'cash-flow',
                'profit-loss',
            ]);

            return response()->json([
                'message' => 'Pembayaran berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal menghapus pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get unpaid orders for payment form
     */
    public function getUnpaidOrders(Request $request)
    {
        $search = $request->get('search', '');

        $orders = Order::with(['customer'])
            ->where('total_price', '>', 0)
            ->where(function ($q) use ($search) {
                if ($search) {
                    $q->where('code', 'LIKE', "%{$search}%")
                      ->orWhereHas('customer', function ($qCustomer) use ($search) {
                          $qCustomer->where('name', 'LIKE', "%{$search}%")
                              ->orWhere('phone', 'LIKE', "%{$search}%");
                      });
                }
            })
            ->orderBy('date', 'DESC')
            ->limit(50)
            ->get();

        // Filter and transform
        $orders->transform(function ($order) {
            $totalPaid = Payment::where('orders_id', $order->id)->sum('nominal');
            $credit = $order->total_price - $totalPaid;

            // Only return orders with outstanding balance
            if ($credit > 0) {
                return [
                    'id' => $order->id,
                    'code' => $order->code,
                    'date' => $order->date,
                    'total_price' => $order->total_price,
                    'total_paid' => $totalPaid,
                    'credit' => $credit,
                    'customer_name' => $order->customer->name,
                    'customer_phone' => $order->customer->phone,
                ];
            }
            return null;
        })->filter(); // Remove null values

        return response()->json($orders->values());
    }
}
