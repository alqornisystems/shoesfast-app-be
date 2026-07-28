<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Guarantee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Send;
use App\Models\Treatment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    private const WINDOW_DAYS = 3;

    private const STATUS_LABELS = [
        0 => 'Menunggu ditinjau',
        1 => 'Disetujui',
        2 => 'Ditolak',
    ];

    // POST /api/customer/orders/{id}/items/{itemId}/claim
    public function store(Request $request, int $id, int $itemId): JsonResponse
    {
        $customer = $request->user();

        $validated = $request->validate([
            'note' => ['required', 'string'],
            'photo' => ['nullable', 'string'],
        ]);

        $order = Order::withoutGlobalScope('branch')
            ->where('id', $id)
            ->where('customers_id', $customer->id)
            ->where('projects_id', $customer->projects_id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan'], 404);
        }

        $item = OrderItem::withoutGlobalScope('branch')
            ->where('id', $itemId)
            ->where('orders_id', $order->id)
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Barang tidak ditemukan'], 404);
        }

        $pendingExists = Guarantee::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)
            ->where('status', 0)
            ->exists();

        if ($pendingExists) {
            return response()->json(['message' => 'Klaim untuk barang ini masih diproses.'], 422);
        }

        $referenceDate = $this->referenceDate($order, $item);

        if ($referenceDate === null) {
            return response()->json([
                'message' => 'Barang ini belum punya tanggal terima. Hubungi toko.',
            ], 422);
        }

        // Batas dipaksakan di server. Menyembunyikan tombol di portal tidak
        // menahan siapa pun yang menembak endpoint ini langsung.
        if (time() > $referenceDate + (self::WINDOW_DAYS * 86400)) {
            return response()->json([
                'message' => 'Masa klaim garansi '.self::WINDOW_DAYS.' hari sudah lewat.',
            ], 422);
        }

        $claim = Guarantee::withoutGlobalScope('branch')->create([
            'projects_id' => $order->projects_id,
            'orders_items_id' => $item->id,
            'price' => null,
            'note' => $validated['note'],
            'photo' => $validated['photo'] ?? null,
            'status' => 0,
        ]);

        return response()->json(['id' => $claim->id, 'status' => 0], 201);
    }

    // GET /api/customer/claims
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $itemIds = OrderItem::withoutGlobalScope('branch')
            ->whereIn('orders_id', Order::withoutGlobalScope('branch')
                ->where('customers_id', $customer->id)
                ->where('projects_id', $customer->projects_id)
                ->pluck('id'))
            ->pluck('id');

        $claims = Guarantee::withoutGlobalScope('branch')
            ->with('item')
            ->whereIn('orders_items_id', $itemIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Guarantee $claim) => [
                'id' => $claim->id,
                'item_name' => $claim->item?->name,
                'note' => $claim->note,
                'status' => $claim->status,
                'status_label' => self::STATUS_LABELS[$claim->status] ?? 'Tidak diketahui',
                'date' => $claim->created_at,
            ]);

        return response()->json(['data' => $claims]);
    }

    /**
     * Tanggal acuan berjenjang, memakai yang paling akhir tersedia:
     *   1. pengiriman selesai untuk barang itu    (3.651 barang)
     *   2. pengiriman selesai di pesanannya       (783 baris tanpa orders_items_id)
     *   3. done_at terakhir dari treatment barang (8.040 barang)
     *
     * Memakai done_at saja berarti menghitung garansi sejak sepatu selesai
     * dikerjakan, bukan sejak pelanggan menerimanya — sepatu yang menginap
     * seminggu di toko sudah kehabisan garansi sebelum dipegang pemiliknya.
     */
    private function referenceDate(Order $order, OrderItem $item): ?int
    {
        $itemDelivery = Send::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)
            ->where('type', 1)->where('status', 1)
            ->max('date');

        if ($itemDelivery) {
            return (int) $itemDelivery;
        }

        $orderDelivery = Send::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)
            ->whereNull('orders_items_id')
            ->where('type', 1)->where('status', 1)
            ->max('date');

        if ($orderDelivery) {
            return (int) $orderDelivery;
        }

        $doneAt = Treatment::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)
            ->max('done_at');

        return $doneAt ? (int) $doneAt : null;
    }
}
