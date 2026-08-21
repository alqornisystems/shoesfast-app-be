<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Send;
use App\Models\Service;
use App\Models\Treatment;
use App\Support\Base64Image;
use App\Support\ItemChecklist;
use App\Support\OrderProgress;
use App\Support\ServiceDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menyunting isi pesanan yang masih berjalan.
 *
 * Batasnya satu kalimat: yang belum disentuh bengkel masih milik pelanggan, yang
 * sudah di rak milik petugas. Itu bukan aturan buatan — barang yang sudah dibongkar
 * teknisi tidak bisa "dihapus" lewat tombol, dan barang yang sudah selesai tidak bisa
 * ditambahi pekerjaan tanpa membongkarnya lagi.
 *
 * Harga tidak pernah dibaca dari badan permintaan di sini, sama seperti saat pesanan
 * dibuat. Yang diterima cuma id layanan.
 */
class OrderItemController extends Controller
{
    /** Pesanan yang sudah ditutup tidak menerima barang baru. */
    private const ORDER_SELESAI = 3;

    // POST /api/customer/orders/{id}/items
    public function store(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        $order = $this->pesanan($customer, $id);

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan'], 404);
        }

        if ((int) $order->status === self::ORDER_SELESAI) {
            return response()->json([
                'message' => 'Pesanan ini sudah selesai. Buat pesanan baru untuk barang tambahan.',
            ], 422);
        }

        $validated = $request->validate([
            'type' => ['required', 'integer', 'in:0,1,2'],
            'name' => ['required', 'string', 'max:100'],
            'checkbox' => ['nullable', 'array'],
            'checkbox.*' => ['boolean'],
            'note' => ['nullable', 'string'],
            'photo' => ['nullable', 'string', 'max:1300000'],
            'services' => ['nullable', 'array', 'max:10'],
            'services.*' => ['integer', 'exists:services,id'],
            // Barang tambahan belum ada di bengkel. Menambahkannya tanpa menanyakan
            // caranya sampai berarti menaruh baris yang tidak akan pernah ada wujudnya
            // di daftar pekerjaan teknisi.
            'arrival.method' => ['required', 'in:jemput,antar_sendiri'],
            'arrival.date' => ['nullable', 'date'],
        ]);

        if (($validated['arrival']['method'] ?? null) === 'jemput') {
            $galat = $this->tolakHariTutup($validated['arrival']['date'] ?? null);

            if ($galat) {
                return $galat;
            }
        }

        $item = DB::transaction(function () use ($order, $validated) {
            $item = OrderItem::withoutGlobalScope('branch')->create([
                'projects_id' => $order->projects_id,
                'orders_id' => $order->id,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'price' => 0,
                'discount' => 0,
                'status' => 0,
                'note' => $validated['note'] ?? null,
                'photo' => $this->simpanFoto($validated['photo'] ?? null, $order->id),
                'checkbox' => ItemChecklist::serialize((int) $validated['type'], $validated['checkbox'] ?? []),
            ]);

            $this->syncTreatments($item, $validated['services'] ?? [], $order);

            if ($validated['arrival']['method'] === 'jemput') {
                Send::withoutGlobalScope('branch')->create([
                    'projects_id' => $order->projects_id,
                    'orders_id' => $order->id,
                    'orders_items_id' => $item->id,
                    // 0 = kurir belum ditugaskan; kolomnya NOT NULL di produksi.
                    'users_id' => 0,
                    'date' => ! empty($validated['arrival']['date'])
                        ? strtotime($validated['arrival']['date'])
                        : time(),
                    'type' => 0,
                    'status' => 0,
                ]);
            }

            return $item;
        });

        return response()->json(['id' => $item->id], 201);
    }

    // PATCH /api/customer/orders/{id}/items/{itemId}
    public function update(Request $request, int $id, int $itemId): JsonResponse
    {
        [$order, $item, $galat] = $this->cariBarang($request->user(), $id, $itemId);

        if ($galat) {
            return $galat;
        }

        $izin = OrderProgress::permissions(OrderProgress::stateOf($order, $item));

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string'],
            'checkbox' => ['sometimes', 'nullable', 'array'],
            'checkbox.*' => ['boolean'],
            'services' => ['sometimes', 'array', 'max:10'],
            'services.*' => ['integer', 'exists:services,id'],
        ]);

        if (array_key_exists('name', $validated) && ! $izin['can_rename']) {
            return response()->json([
                'message' => 'Barang ini sudah selesai dikerjakan, namanya tidak bisa diubah lagi.',
            ], 422);
        }

        if (array_key_exists('services', $validated) && ! $izin['can_change_services']) {
            return response()->json([
                'message' => 'Barang ini sudah selesai. Untuk pekerjaan tambahan, buat pesanan baru.',
            ], 422);
        }

        DB::transaction(function () use ($item, $order, $validated) {
            $isian = [];

            foreach (['name', 'note'] as $kolom) {
                if (array_key_exists($kolom, $validated)) {
                    $isian[$kolom] = $validated[$kolom];
                }
            }

            if (array_key_exists('checkbox', $validated)) {
                $isian['checkbox'] = ItemChecklist::serialize(
                    (int) $item->type,
                    $validated['checkbox'] ?? []
                );
            }

            if ($isian) {
                $item->update($isian);
            }

            if (array_key_exists('services', $validated)) {
                $this->syncTreatments($item, $validated['services'], $order);
            }
        });

        return response()->json(['id' => $item->id]);
    }

    // DELETE /api/customer/orders/{id}/items/{itemId}
    public function destroy(Request $request, int $id, int $itemId): JsonResponse
    {
        [$order, $item, $galat] = $this->cariBarang($request->user(), $id, $itemId);

        if ($galat) {
            return $galat;
        }

        if (! OrderProgress::permissions(OrderProgress::stateOf($order, $item))['can_remove']) {
            return response()->json([
                'message' => 'Barang ini sudah mulai dikerjakan. Hubungi toko kalau mau membatalkannya.',
            ], 422);
        }

        DB::transaction(function () use ($item) {
            // Treatment ikut dihapus: baris yang menunjuk barang yang tidak ada lagi
            // akan muncul di waiting list teknisi sebagai pekerjaan tanpa wujud.
            Treatment::withoutGlobalScope('branch')
                ->where('orders_items_id', $item->id)
                ->update(['is_deleted' => 1]);

            // is_deleted, BUKAN $item->delete(). Model ini tidak mengoverride delete()
            // seperti sebagian model lain di skema ini, jadi memanggilnya menghapus
            // barisnya betulan — foto, catatan, dan jejak harganya hilang permanen
            // hanya karena pelanggan salah ketik satu barang.
            $item->update(['is_deleted' => 1]);
        });

        return response()->json(['deleted' => true]);
    }

    /**
     * Menyamakan daftar layanan barang ini dengan pilihan pelanggan.
     *
     * Treatment yang SUDAH jalan atau sudah selesai tidak disentuh sama sekali —
     * pekerjaan yang sudah dikerjakan tidak bisa dibatalkan dengan menghapus barisnya,
     * dan teknisi yang sudah mengerjakannya tetap berhak tercatat.
     *
     * @param  list<int>  $serviceIds
     */
    private function syncTreatments(OrderItem $item, array $serviceIds, Order $order): void
    {
        $antre = Treatment::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)
            ->where('status', 0)
            ->whereNull('done_at')
            ->get();

        $diminta = collect($serviceIds)->countBy();
        $adaSekarang = $antre->groupBy('services_id');

        // Yang tidak lagi diminta: dicabut, tapi hanya dari yang masih mengantre.
        foreach ($adaSekarang as $serviceId => $baris) {
            $sisaDiminta = (int) ($diminta[$serviceId] ?? 0);

            foreach ($baris->slice($sisaDiminta) as $treatment) {
                $treatment->update(['is_deleted' => 1]);
            }
        }

        $mulaiDari = max(time(), (int) $order->date);
        $sebelumnya = null;

        foreach ($diminta as $serviceId => $jumlah) {
            $kurang = $jumlah - ($adaSekarang[$serviceId] ?? collect())->count();

            for ($i = 0; $i < $kurang; $i++) {
                $service = Service::withoutGlobalScope('branch')->find($serviceId);

                if (! $service) {
                    continue;
                }

                $mulai = $sebelumnya === null ? $mulaiDari : strtotime('+1 day', $sebelumnya);
                $selesai = strtotime('+'.(int) $service->estimation.' day', $mulai);

                Treatment::withoutGlobalScope('branch')->create([
                    'projects_id' => $order->projects_id,
                    'orders_items_id' => $item->id,
                    'services_id' => $service->id,
                    // Harga dari katalog, tidak pernah dari permintaan.
                    'price' => (int) $service->price,
                    'date_start' => $mulai,
                    'date_end' => $selesai,
                    'status' => 0,
                ]);

                $sebelumnya = $selesai;
            }
        }
    }

    /** @return array{0: ?Order, 1: ?OrderItem, 2: ?JsonResponse} */
    private function cariBarang(Customer $customer, int $id, int $itemId): array
    {
        $order = $this->pesanan($customer, $id);

        if (! $order) {
            return [null, null, response()->json(['message' => 'Pesanan tidak ditemukan'], 404)];
        }

        $item = OrderItem::withoutGlobalScope('branch')
            ->with('treatments')
            ->where('id', $itemId)
            ->where('orders_id', $order->id)
            ->first();

        if (! $item) {
            return [null, null, response()->json(['message' => 'Barang tidak ditemukan'], 404)];
        }

        return [$order, $item, null];
    }

    private function pesanan(Customer $customer, int $id): ?Order
    {
        return Order::withoutGlobalScope('branch')
            ->where('id', $id)
            ->where('customers_id', $customer->id)
            ->where('projects_id', $customer->projects_id)
            ->first();
    }

    private function tolakHariTutup(?string $tanggal): ?JsonResponse
    {
        if (empty($tanggal)) {
            return null;
        }

        $unix = strtotime($tanggal);
        $tutup = ServiceDay::closedReason($unix);

        if ($tutup === null) {
            return null;
        }

        return response()->json([
            'message' => $tutup.'. Pilih hari lain, misalnya '
                .date('j F Y', ServiceDay::nextOpen($unix + 86400)).'.',
            'errors' => ['arrival.date' => [$tutup.', kurir tidak berangkat.']],
        ], 422);
    }

    private function simpanFoto(?string $foto, int $orderId): ?string
    {
        if (empty($foto) || ! str_starts_with($foto, 'data:image/')) {
            return null;
        }

        return Base64Image::store($foto, 'orders_items', 'item-'.$orderId.'-'.uniqid());
    }
}
