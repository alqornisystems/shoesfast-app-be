<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Send;
use App\Support\OrderProgress;
use App\Support\ServiceDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Permintaan pelanggan untuk membawa pulang barangnya.
 *
 * PERMINTAAN, bukan penugasan. Yang tersimpan adalah baris sends berstatus menunggu;
 * kurirnya ditentukan petugas seperti biasa. Sistem tidak boleh menjanjikan kurir
 * datang Selasa pagi tanpa ada seorang pun yang menyanggupinya.
 *
 * Per barang, bukan per pesanan: satu pesanan bisa berisi tiga pasang sepatu yang
 * selesai di hari berbeda, dan menahan yang pertama sampai yang ketiga beres adalah
 * menahan barang orang tanpa alasan.
 */
class HandoverController extends Controller
{
    // POST /api/customer/orders/{id}/handover
    public function store(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer'],
            'method' => ['required', 'in:ambil_sendiri,diantar'],
            'date' => ['nullable', 'date'],
        ]);

        $order = Order::withoutGlobalScope('branch')
            ->with('project')
            ->where('id', $id)
            ->where('customers_id', $customer->id)
            ->where('projects_id', $customer->projects_id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan'], 404);
        }

        // Bengkel tutup Minggu dan tanggal merah — untuk kurir maupun untuk pelanggan
        // yang datang sendiri. Menerima tanggalnya diam-diam berarti menyuruh orang
        // datang ke pintu yang terkunci.
        if (! empty($validated['date'])) {
            $unix = strtotime($validated['date']);
            $tutup = ServiceDay::closedReason($unix);

            if ($tutup !== null) {
                return response()->json([
                    'message' => $tutup.'. Pilih hari lain, misalnya '
                        .date('j F Y', ServiceDay::nextOpen($unix + 86400)).'.',
                    'errors' => ['date' => [$tutup.', kami tutup.']],
                ], 422);
            }
        }

        $items = OrderItem::withoutGlobalScope('branch')
            ->with('treatments')
            ->whereIn('id', $validated['items'])
            ->where('orders_id', $order->id)
            ->get();

        if ($items->count() !== count(array_unique($validated['items']))) {
            return response()->json(['message' => 'Ada barang yang tidak ditemukan di pesanan ini.'], 404);
        }

        $progress = new OrderProgress($order, $items);

        foreach ($items as $item) {
            $keadaan = $progress->item($item);

            if ($keadaan['state'] !== OrderProgress::SIAP) {
                return response()->json([
                    'message' => "\"{$item->name}\" belum selesai dikerjakan, jadi belum bisa diambil.",
                ], 422);
            }

            if (! $keadaan['can_take']) {
                return response()->json([
                    'message' => "Tagihan \"{$item->name}\" belum lunas. Lunasi dulu, lalu barangnya bisa langsung diambil.",
                ], 422);
            }
        }

        $tanggal = ! empty($validated['date'])
            ? strtotime($validated['date'])
            : ServiceDay::nextOpen(time());

        DB::transaction(function () use ($items, $order, $validated, $tanggal) {
            foreach ($items as $item) {
                Send::withoutGlobalScope('branch')->create([
                    'projects_id' => $order->projects_id,
                    'orders_id' => $order->id,
                    'orders_items_id' => $item->id,
                    // 0 = kurir belum ditugaskan; kolomnya NOT NULL di produksi.
                    'users_id' => 0,
                    'date' => $tanggal,
                    // type 1 = pulang ke pelanggan, dipakai kedua cara. Bedanya ditulis
                    // di catatan: yang diambil sendiri tidak butuh kurir, tapi tetap
                    // perlu tercatat supaya petugas menyiapkan barangnya di depan.
                    'type' => 1,
                    'status' => 0,
                    'note' => $validated['method'] === 'ambil_sendiri'
                        ? 'Diambil sendiri oleh pelanggan'
                        : 'Diantar kurir — permintaan dari portal',
                ]);
            }
        });

        return response()->json([
            'requested' => $items->count(),
            'date' => $tanggal,
            'method' => $validated['method'],
        ], 201);
    }
}
