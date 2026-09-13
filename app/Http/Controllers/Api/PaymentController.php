<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CustomerPointService;
use App\Services\ReportCacheService;
use App\Support\Base64Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    use \App\Http\Controllers\Concerns\DaftarTersortir;

    /** Kolom yang boleh dipakai mengurutkan daftar ini. */
    private const SORTIR_PEMBAYARAN = [
        'date' => 'orders.date',
        'order_code' => 'orders.code',
        'total' => 'orders.total_price',
        'customer' => '(SELECT c.name FROM customers c WHERE c.id = orders.customers_id)',
    ];

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

        // Filter by payment status, satu atau beberapa sekaligus ("unpaid,partial").
        // Pesanan Selesai dianggap lunas (Order::sisaTagihan): ikut "paid" walau
        // pembayarannya kurang, dan tidak pernah muncul sebagai "unpaid"/"partial".
        if ($request->has('status') && $request->status !== '') {
            $sudahBayar = '(SELECT COALESCE(SUM(nominal), 0) FROM payments WHERE orders_id = orders.id AND is_deleted = 0)';
            $selesai = Order::STATUS_SELESAI;

            $query->where(function ($q) use ($request, $sudahBayar, $selesai) {
                foreach (array_map('trim', explode(',', $request->status)) as $status) {
                    $q->orWhere(function ($s) use ($status, $sudahBayar, $selesai) {
                        if ($status === 'paid') {
                            $s->where('orders.status', $selesai)
                                ->orWhere(fn ($x) => $x->whereRaw("total_price = {$sudahBayar}")->whereHas('payments'));
                        } elseif ($status === 'unpaid') {
                            $s->where('orders.status', '!=', $selesai)->whereDoesntHave('payments');
                        } elseif ($status === 'partial') {
                            $s->where('orders.status', '!=', $selesai)
                                ->whereRaw("total_price > {$sudahBayar}")
                                ->whereHas('payments');
                        }
                    });
                }
            });
        }

        $perPage = $request->get('per_page', 15);
        $request->validate($this->aturanSortir(self::SORTIR_PEMBAYARAN));
        $this->terapkanSortir($query, $request, self::SORTIR_PEMBAYARAN, 'orders.id');

        $orders = $query->paginate($perPage);

        // Transform data with payment info
        $orders->getCollection()->transform(function ($order) {
            $dueDate = strtotime(date('Y-m-d', strtotime(date('Y-m-d', $order->date).' +3 days')));

            // Get all payments for this order and sum them
            $totalPaid = Payment::where('orders_id', $order->id)->sum('nominal');

            // Get latest payment for display
            $payment = Payment::where('orders_id', $order->id)
                ->orderBy('date', 'DESC')
                ->first();
            $credit = $order->sisaTagihan((int) $totalPaid);

            // Calculate late days (only if unpaid and past due)
            $late = '-';
            if ($totalPaid === 0 && $dueDate < time()) {
                $lateDays = floor((time() - $dueDate) / 86400);
                $late = $lateDays.' hari';
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
                'branch_name' => $order->project ? $order->project->name : null,
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
            // Opsional: kasir boleh menunjuk barang mana yang dilunasi pembayaran ini.
            // Dikosongkan berarti untuk pesanannya secara umum — itu keadaan normal,
            // bukan data yang kurang lengkap.
            'orders_items_id' => 'nullable|integer',
            'date' => 'required|date',
            'nominal' => 'required|integer|min:1',
            'note' => 'nullable|string',
            'photo' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Check if order exists and get total price
            $order = Order::findOrFail($validated['orders_id']);

            // Barang yang ditunjuk harus milik pesanan itu. Tanpa pemeriksaan ini satu
            // salah ketik id memindahkan pelunasan ke pesanan orang lain, dan barang
            // yang sudah dibayar tetap tertahan sementara barang orang asing terbuka.
            if (! empty($validated['orders_items_id'])) {
                $milikPesanan = \App\Models\OrderItem::where('id', $validated['orders_items_id'])
                    ->where('orders_id', $order->id)
                    ->exists();

                if (! $milikPesanan) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Barang itu bukan bagian dari pesanan ini.',
                    ], 422);
                }
            }

            // Handle photo upload (base64)
            $photoPath = null;
            if (isset($validated['photo']) && $validated['photo']) {
                $photoPath = $this->saveBase64Image($validated['photo'], 'payments');
            }

            // Always create new payment (support multiple payments/cicilan)
            $payment = Payment::create([
                'orders_id' => $validated['orders_id'],
                'orders_items_id' => $validated['orders_items_id'] ?? null,
                'date' => strtotime($validated['date']),
                'nominal' => $validated['nominal'],
                'note' => $validated['note'] ?? null,
                'photo' => $photoPath,
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            // Poin diberikan di sini, bukan saat status pesanan berubah:
            // kelunasan adalah satu-satunya sinyal yang artinya tidak pernah
            // berubah. Idempoten lewat orders.points_awarded, jadi cicilan
            // kedua dan ketiga tidak menambah poin lagi.
            app(CustomerPointService::class)->awardIfSettled((int) $validated['orders_id']);

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
    /**
     * Bukti pembayaran. Pendekodean, pelurusan orientasi, dan penyesuaian lebar ke 1080px
     * dikerjakan Base64Image bersama seluruh jalur unggah lain.
     *
     * Awalan "storage/" pada nilai kembalian DIPERTAHANKAN: baris pembayaran lama sudah
     * menyimpannya dalam bentuk itu, dan mengubah bentuknya sekarang membuat foto lama
     * dan baru butuh dua aturan pembacaan yang berbeda.
     */
    private function saveBase64Image($base64String, $folder = 'payments')
    {
        if (! preg_match('/^data:image\/(\w+);base64,/', $base64String)) {
            return null;
        }

        return 'storage/'.Base64Image::store($base64String, $folder);
    }

    /**
     * Get payment history by order ID
     */
    public function getByOrder($orderId)
    {
        $payments = Payment::with(['order.customer', 'user:id,name'])
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

            // Cermin dari store(): kalau penghapusan ini membuat pesanan kurang bayar
            // lagi, poin yang diberikan karena kelunasannya ditarik dan penandanya
            // dibuka supaya pelunasan berikutnya memberi poin lagi.
            app(CustomerPointService::class)->revokeIfUnsettled((int) $payment->orders_id);

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
            // Pesanan Selesai dianggap lunas, jadi tidak ditawarkan untuk dibayar.
            ->where('status', '!=', Order::STATUS_SELESAI)
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
