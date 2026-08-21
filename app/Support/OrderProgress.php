<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Send;
use Illuminate\Support\Collection;

/**
 * Perjalanan sebuah pesanan, dilihat PER BARANG.
 *
 * Satu pesanan bisa berisi tiga pasang sepatu yang selesai di hari yang berbeda.
 * Status di tingkat pesanan menyembunyikan itu: pelanggan yang sepatunya sudah
 * selesai sejak Selasa tetap membaca "Sedang dikerjakan" sampai sepatu terakhir
 * beres, dan tidak pernah tahu ia sudah boleh mengambil yang pertama.
 *
 * Kelas ini menjawab tiga pertanyaan untuk tiap barang: sedang di mana, siapa yang
 * mengerjakan, dan berapa yang harus dibayar sebelum ia boleh dibawa pulang.
 */
class OrderProgress
{
    public const MENUNGGU = 'menunggu';

    public const DIKERJAKAN = 'dikerjakan';

    public const SIAP = 'siap';

    public const DIANTAR = 'diantar';

    public const DITERIMA = 'diterima';

    public const BATAL = 'batal';

    private const LABEL = [
        self::MENUNGGU => 'Menunggu diproses',
        self::DIKERJAKAN => 'Sedang dikerjakan',
        self::SIAP => 'Siap diambil',
        self::DIANTAR => 'Dalam pengantaran',
        self::DITERIMA => 'Sudah diterima',
        self::BATAL => 'Dibatalkan',
    ];

    /** orders_items.status: 2 berarti seluruh treatment barang itu sudah selesai. */
    private const ITEM_SELESAI = 2;

    private const ITEM_BATAL = 3;

    /** @var Collection<int, Send> */
    private Collection $sends;

    /** @var array<int, int> orders_items_id => nominal terbayar */
    private array $terbayar;

    /**
     * @param  Collection<int, OrderItem>  $items  sudah memuat relasi treatments.service,
     *                                             treatments.user, dan treatments.partnership
     */
    public function __construct(
        private Order $order,
        private Collection $items,
    ) {
        $this->sends = Send::withoutGlobalScopes()
            ->with('user')
            ->where('orders_id', $order->id)
            ->where('is_deleted', 0)
            ->orderBy('date')
            ->get();

        $this->terbayar = $this->alokasikanPembayaran();
    }

    /**
     * Ringkasan tingkat pesanan yang DITURUNKAN dari barang-barangnya.
     *
     * "Siap diambil" baru muncul kalau semua barang memang siap. Selama masih ada yang
     * di bengkel, yang benar adalah "sebagian siap" — dan itu justru kabar yang
     * ditunggu: ada yang sudah boleh diambil sekarang.
     *
     * @return array{state: string, label: string, ready: int, total: int, taken: int}
     */
    public function summary(): array
    {
        $keadaan = $this->items
            ->reject(fn (OrderItem $item) => $this->state($item) === self::BATAL)
            ->map(fn (OrderItem $item) => $this->state($item));

        $total = $keadaan->count();
        $siap = $keadaan->filter(fn ($s) => $s === self::SIAP)->count();
        $diterima = $keadaan->filter(fn ($s) => $s === self::DITERIMA)->count();

        if ($total === 0) {
            return ['state' => self::MENUNGGU, 'label' => self::LABEL[self::MENUNGGU],
                'ready' => 0, 'total' => 0, 'taken' => 0];
        }

        $state = match (true) {
            $diterima === $total => self::DITERIMA,
            $siap + $diterima === $total => self::SIAP,
            $siap > 0 => self::SIAP,
            $keadaan->contains(self::DIANTAR) => self::DIANTAR,
            $keadaan->contains(self::DIKERJAKAN) => self::DIKERJAKAN,
            default => self::MENUNGGU,
        };

        // Sebagian siap ditulis apa adanya. "Siap diambil" untuk pesanan yang dua dari
        // tiga barangnya masih dikerjakan adalah janji yang tidak dipenuhi di kasir.
        $label = ($state === self::SIAP && $siap + $diterima < $total)
            ? "Sebagian siap diambil ({$siap} dari {$total})"
            : self::LABEL[$state];

        return [
            'state' => $state,
            'label' => $label,
            'ready' => $siap,
            'total' => $total,
            'taken' => $diterima,
        ];
    }

    /**
     * Keadaan satu barang, lengkap dengan tagihan dan riwayatnya.
     *
     * @return array<string, mixed>
     */
    public function item(OrderItem $item): array
    {
        $state = $this->state($item);
        $harga = (int) $item->price;
        $bayar = $this->terbayar[$item->id] ?? 0;
        $sisa = $harga > 0 ? max(0, $harga - $bayar) : null;

        return [
            'state' => $state,
            'state_label' => self::LABEL[$state],
            'location' => $this->location($item, $state),
            'price' => $harga > 0 ? $harga : null,
            'paid' => $bayar,
            'credit' => $sisa,
            // Barang hanya boleh dibawa pulang setelah tagihannya sendiri lunas.
            // Barang yang harganya belum ditentukan belum bisa dilunasi, jadi belum
            // bisa diambil juga — dan itu memang benar: kasir tidak punya angka.
            'can_take' => $state === self::SIAP && $harga > 0 && $sisa === 0,
            'history' => $this->history($item),
        ];
    }

    private function state(OrderItem $item): string
    {
        if ((int) $item->status === self::ITEM_BATAL) {
            return self::BATAL;
        }

        if ($this->pengantaran($item, selesai: true)) {
            return self::DITERIMA;
        }

        if ($this->pengantaran($item, selesai: false)) {
            return self::DIANTAR;
        }

        if ((int) $item->status === self::ITEM_SELESAI) {
            return self::SIAP;
        }

        // Satu treatment yang sudah jalan sudah cukup untuk menyebut barangnya
        // dikerjakan — pelanggan tidak peduli tiga layanan lain masih antre.
        $adaYangJalan = $item->treatments->contains(
            fn ($t) => (int) $t->status > 0 || $t->done_at
        );

        return $adaYangJalan ? self::DIKERJAKAN : self::MENUNGGU;
    }

    /** Di mana barangnya sekarang, dalam kalimat yang bisa langsung dibaca. */
    private function location(OrderItem $item, string $state): string
    {
        $cabang = $this->order->project?->name;
        $diBengkel = $cabang ? "Bengkel {$cabang}" : 'Bengkel kami';

        return match ($state) {
            self::BATAL => 'Barang ini dibatalkan',
            self::DITERIMA => 'Sudah di tangan kamu',
            self::DIANTAR => 'Sedang diantar kurir ke alamatmu',
            self::SIAP => "Selesai, menunggu diambil di {$diBengkel}",
            self::DIKERJAKAN => "Sedang dikerjakan di {$diBengkel}",
            default => $this->penjemputan()
                ? "Sudah sampai di {$diBengkel}, menunggu antrean"
                : 'Menunggu dijemput kurir',
        };
    }

    /**
     * Riwayat satu barang, urut waktu. Yang belum terjadi tetap ditulis dengan
     * done=false: langkah yang hilang tidak memberi tahu siapa pun bahwa ia akan
     * datang, dan pertanyaan "habis ini apa lagi" adalah pertanyaan yang paling
     * sering ditanyakan ke WhatsApp toko.
     *
     * @return list<array<string, mixed>>
     */
    private function history(OrderItem $item): array
    {
        $riwayat = [[
            'key' => 'dipesan',
            'label' => 'Pesanan dibuat',
            'detail' => null,
            'date' => (int) $this->order->date,
            'done' => true,
        ]];

        $jemput = $this->penjemputan();

        if ($jemput) {
            $riwayat[] = [
                'key' => 'dijemput',
                'label' => 'Dijemput kurir',
                'detail' => $jemput->user?->name ? 'Oleh '.$jemput->user->name : null,
                'date' => (int) $jemput->date,
                'done' => true,
            ];
        }

        foreach ($item->treatments as $treatment) {
            $selesai = (int) $treatment->status === 2 || $treatment->done_at;

            $riwayat[] = [
                'key' => 'layanan-'.$treatment->id,
                'label' => $treatment->service?->name ?? 'Layanan',
                'detail' => $this->pengerja($treatment),
                'date' => $treatment->done_at ?: $treatment->date_start,
                'done' => (bool) $selesai,
                'price' => (int) $treatment->price ?: null,
            ];
        }

        $state = $this->state($item);

        $riwayat[] = [
            'key' => 'siap',
            'label' => 'Selesai dikerjakan',
            'detail' => null,
            'date' => $item->treatments->max('done_at') ?: null,
            'done' => in_array($state, [self::SIAP, self::DIANTAR, self::DITERIMA], true),
        ];

        $antar = $this->pengantaran($item, selesai: true) ?? $this->pengantaran($item, selesai: false);

        $riwayat[] = [
            'key' => 'diterima',
            'label' => $antar && (int) $antar->status === 1
                ? 'Diterima kamu'
                : 'Diambil atau diantar',
            'detail' => $antar?->user?->name ? 'Kurir '.$antar->user->name : null,
            'date' => $antar ? (int) $antar->date : null,
            'done' => $state === self::DITERIMA,
        ];

        return $riwayat;
    }

    /** Nama yang mengerjakan: teknisi kami, atau mitra kalau dikerjakan di luar. */
    private function pengerja($treatment): ?string
    {
        if ((int) $treatment->is_partnerships === 1) {
            return $treatment->partnership?->name
                ? 'Dikerjakan mitra '.$treatment->partnership->name
                : 'Dikerjakan mitra kami';
        }

        return $treatment->user?->name
            ? 'Dikerjakan '.$treatment->user->name
            : null;
    }

    private function penjemputan(): ?Send
    {
        return $this->sends->first(
            fn (Send $s) => (int) $s->type === 0 && (int) $s->status === 1
        );
    }

    /** Pengiriman pulang untuk barang ini — per barang dulu, baru per pesanan. */
    private function pengantaran(OrderItem $item, bool $selesai): ?Send
    {
        $status = $selesai ? 1 : 0;

        return $this->sends->first(fn (Send $s) => (int) $s->type === 1
                && (int) $s->status === $status
                && (int) $s->orders_items_id === $item->id)
            ?? $this->sends->first(fn (Send $s) => (int) $s->type === 1
                && (int) $s->status === $status
                && $s->orders_items_id === null);
    }

    /**
     * Membagi pembayaran pesanan ke barang-barangnya.
     *
     * Tabel payments hanya punya orders_id — tidak ada kolom yang menyebut pembayaran
     * ini untuk barang yang mana. Jadi pembagiannya ditebak, dengan aturan yang paling
     * mendekati kenyataan kasir: barang yang SUDAH SIAP dilunasi lebih dulu, karena
     * uang memang berpindah saat barang diserahkan.
     *
     * ponytail: tebakan. Kalau suatu saat kasir perlu menunjuk barang secara pasti,
     * yang dibutuhkan kolom orders_items_id di payments — bukan aturan yang lebih
     * pintar di sini.
     *
     * @return array<int, int>
     */
    private function alokasikanPembayaran(): array
    {
        $kas = (int) Payment::withoutGlobalScope('branch')
            ->where('orders_id', $this->order->id)
            ->sum('nominal');

        $hasil = [];

        $urutan = $this->items
            ->sortBy(fn (OrderItem $item) => [
                (int) $item->status === self::ITEM_SELESAI ? 0 : 1,
                $item->id,
            ])
            ->values();

        foreach ($urutan as $item) {
            $harga = (int) $item->price;
            $ambil = min($kas, max(0, $harga));

            $hasil[$item->id] = $ambil;
            $kas -= $ambil;
        }

        return $hasil;
    }
}
