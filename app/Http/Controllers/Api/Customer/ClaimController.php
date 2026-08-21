<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Guarantee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\WarrantyWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
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

        // Aturannya dibaca dari WarrantyWindow, sumber yang sama dengan yang dipakai
        // detail pesanan untuk memutuskan tombolnya muncul. Batas ini tetap dipaksakan
        // di server: menyembunyikan tombol di portal tidak menahan siapa pun yang
        // menembak endpoint ini langsung.
        $klaim = WarrantyWindow::status($order, $item);

        if (! $klaim['eligible']) {
            return response()->json([
                'message' => match ($klaim['reason']) {
                    'sedang_ditinjau' => 'Klaim untuk barang ini masih diproses.',
                    'belum_selesai' => 'Pesanan ini belum selesai. Klaim dibuka setelah barang kamu terima.',
                    'belum_diterima' => 'Barang ini belum punya tanggal terima. Hubungi toko.',
                    default => 'Masa klaim garansi '.WarrantyWindow::DAYS.' hari sudah lewat.',
                },
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
}
