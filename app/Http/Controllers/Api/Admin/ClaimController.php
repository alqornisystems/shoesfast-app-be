<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Guarantee;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    private const STATUS_LABELS = [
        0 => 'Menunggu ditinjau',
        1 => 'Disetujui',
        2 => 'Ditolak',
    ];

    // GET /api/guarantee-claims
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 25);
        $status = $request->get('status');

        // Klaim yang menunggu selalu di atas: itu yang perlu ditindak.
        $query = Guarantee::orderBy('status')->orderByDesc('created_at');

        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $paginator = $query->paginate($perPage);

        $items = OrderItem::whereIn('id', $paginator->getCollection()->pluck('orders_items_id'))
            ->get()
            ->keyBy('id');

        $orders = Order::whereIn('id', $items->pluck('orders_id'))->get()->keyBy('id');

        $customers = Customer::whereIn('id', $orders->pluck('customers_id')->filter())
            ->pluck('name', 'id');

        $paginator->getCollection()->transform(function (Guarantee $c) use ($items, $orders, $customers) {
            $item = $items[$c->orders_items_id] ?? null;
            $order = $item ? ($orders[$item->orders_id] ?? null) : null;

            return [
                'id' => $c->id,
                // Null-safe di tiap lompatan: barang, pesanan, dan pelanggan
                // semuanya bisa terhapus setelah klaim dibuat.
                'item_name' => $item?->name,
                'order_id' => $order?->id,
                'order_code' => $order?->code,
                'customer_name' => $order ? ($customers[$order->customers_id] ?? null) : null,
                'note' => $c->note,
                'photo' => $c->photo,
                'price' => $c->price,
                'status' => $c->status,
                'status_label' => self::STATUS_LABELS[$c->status] ?? 'Tidak diketahui',
                'date' => $c->created_at,
            ];
        });

        return response()->json($paginator);
    }

    // PUT /api/guarantee-claims/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $claim = Guarantee::find($id);

        if (! $claim) {
            return response()->json(['message' => 'Klaim tidak ditemukan'], 404);
        }

        $validated = $request->validate([
            // 0 tidak diizinkan: mengembalikan klaim ke "menunggu" setelah
            // diputuskan hanya membingungkan pelanggan yang sudah diberi tahu.
            'status' => ['required', 'integer', 'in:1,2'],
            'price' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $claim->update([
            'status' => $validated['status'],
            'price' => $validated['price'] ?? $claim->price,
            'note' => $validated['note'] ?? $claim->note,
            'modified_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Klaim diperbarui']);
    }
}
