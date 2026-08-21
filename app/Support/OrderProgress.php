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

    private const ITEM_PROSES = 1;

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
            'permissions' => self::permissions($state),
            // Tautan lacak khusus barang ini — ada kalau kurirnya berangkat membawa
            // barang ini saja. Yang berangkat membawa seluruh pesanan tidak muncul di
            // sini melainkan di atas halaman: satu kurir satu perjalanan, dan
            // menaruhnya di tiga kartu barang membuatnya terbaca seperti tiga kurir.
            'tracking' => $this->pelacakan($item),
            'history' => $this->history($item),
        ];
    }

    /**
     * Apa yang masih boleh diubah pelanggan atas barang ini.
     *
     * Batasnya bukan aturan buatan: barang yang sudah di rak punya akibat fisik. Nama
     * boleh diganti selama belum selesai karena itu label, bukan pekerjaan. Menghapus
     * barang yang sudah dibongkar teknisi bukan penyuntingan — itu masalah, dan
     * masalah diselesaikan lewat orang, bukan lewat tombol.
     *
     * @return array{can_rename: bool, can_change_services: bool, can_remove: bool}
     */
    public static function permissions(string $state): array
    {
        $belumSelesai = in_array($state, [self::MENUNGGU, self::DIKERJAKAN], true);

        return [
            'can_rename' => $belumSelesai,
            'can_change_services' => $belumSelesai,
            'can_remove' => $state === self::MENUNGGU,
        ];
    }

    /** Keadaan satu barang tanpa membangun seluruh rombongan — dipakai penjaga endpoint. */
    public static function stateOf(Order $order, OrderItem $item): string
    {
        $item->loadMissing('treatments');

        return (new self($order, collect([$item])))->state($item);
    }

    public function state(OrderItem $item): string
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

        // Petugas menandai barangnya sedang diproses, kadang sebelum satu pun baris
        // treatment dibuat. Mengabaikannya berarti barang yang sudah dibongkar di meja
        // teknisi tetap terbaca "menunggu" — dan pelanggan masih ditawari tombol hapus.
        if ((int) $item->status === self::ITEM_PROSES) {
            return self::DIKERJAKAN;
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

    /**
     * Sesi pelacakan yang khusus membawa barang ini.
     *
     * @return array{token: string, type: int, expires_at: int}|null
     */
    private function pelacakan(OrderItem $item): ?array
    {
        $send = $this->sends->first(fn (Send $s) => (int) $s->orders_items_id === $item->id
            && (int) $s->status === 0
            && $s->tracking_token
            && (int) $s->tracking_expires_at > time());

        if (! $send) {
            return null;
        }

        return [
            'token' => $send->tracking_token,
            // 0 = jemput, 1 = antar. Kalimat di layar berbeda: yang satu kurir datang
            // mengambil, yang satu mengantar pulang.
            'type' => (int) $send->type,
            'expires_at' => (int) $send->tracking_expires_at,
        ];
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
     * Dua lapis, dan urutannya penting:
     *
     * 1. Pembayaran yang MENUNJUK barang (payments.orders_items_id terisi) masuk ke
     *    barang itu, titik. Ini fakta, bukan tebakan — kasir yang mencatatnya tahu
     *    persis barang mana yang diserahkan.
     * 2. Sisanya — pembayaran lama dan pelunasan yang memang untuk seluruh pesanan —
     *    dibagi ke barang yang belum tertutup, yang SUDAH SIAP lebih dulu, karena
     *    uang memang berpindah saat barang diserahkan.
     *
     * Lapis kedua tetap tebakan, dan tetap bisa salah menahan barang yang sebenarnya
     * sudah dibayar. Bedanya sekarang ada jalan keluarnya: begitu kasir mengisi
     * orders_items_id, barang itu keluar dari tebakan sepenuhnya.
     *
     * Kelebihan bayar pada satu barang tidak menguap — sisanya turun ke barang lain.
     * Pelanggan yang membayar Rp 300.000 untuk barang berharga Rp 275.000 sudah
     * menyerahkan Rp 25.000 yang tetap miliknya.
     *
     * @return array<int, int>
     */
    private function alokasikanPembayaran(): array
    {
        $pembayaran = Payment::withoutGlobalScope('branch')
            ->where('orders_id', $this->order->id)
            ->get();

        $hasil = [];
        foreach ($this->items as $item) {
            $hasil[$item->id] = 0;
        }

        $kas = 0;

        foreach ($pembayaran as $bayar) {
            $tujuan = (int) $bayar->orders_items_id;
            $nominal = (int) $bayar->nominal;

            // Menunjuk barang yang tidak ada di pesanan ini (data lama yang aneh)
            // diperlakukan seperti tidak menunjuk apa pun, bukan dibuang.
            if ($tujuan && array_key_exists($tujuan, $hasil)) {
                $hasil[$tujuan] += $nominal;

                continue;
            }

            $kas += $nominal;
        }

        // Kelebihan pada barang bertarget dikembalikan ke kas bersama.
        foreach ($this->items as $item) {
            $harga = max(0, (int) $item->price);
            $lebih = $hasil[$item->id] - $harga;

            if ($harga > 0 && $lebih > 0) {
                $hasil[$item->id] = $harga;
                $kas += $lebih;
            }
        }

        $urutan = $this->items
            ->sortBy(fn (OrderItem $item) => [
                (int) $item->status === self::ITEM_SELESAI ? 0 : 1,
                $item->id,
            ])
            ->values();

        foreach ($urutan as $item) {
            if ($kas <= 0) {
                break;
            }

            $kurang = max(0, (int) $item->price) - $hasil[$item->id];

            if ($kurang <= 0) {
                continue;
            }

            $ambil = min($kas, $kurang);
            $hasil[$item->id] += $ambil;
            $kas -= $ambil;
        }

        return $hasil;
    }
}
