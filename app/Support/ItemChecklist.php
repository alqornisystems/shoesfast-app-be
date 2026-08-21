<?php

namespace App\Support;

use App\Models\OrderItem;

/**
 * Kelengkapan barang: daftar centang per jenis, dan cara menyimpannya.
 *
 * Kolom `checkbox` menyimpan deretan boolean BERPOSISI, bukan berlabel —
 * "true, false, true" tidak menyebut satu pun nama. Artinya seluruhnya bergantung
 * pada urutan daftar di bawah, dan menggeser satu baris menggeser arti sepuluh ribu
 * baris data lama sekaligus.
 *
 * Karena itu daftarnya ditulis SATU kali di sini. Sempat ada dua salinan di dua
 * controller, dan salinan kedua itulah cara paling pasti membuat keduanya suatu saat
 * berbeda urutan tanpa ada yang sadar.
 *
 * Jenis "Lainnya" (0) memang tidak punya daftar centang di admin panel: koper,
 * dompet, dan jam tangan tidak berbagi satu set kelengkapan.
 */
class ItemChecklist
{
    private const SEPATU = ['Tali Sepatu', 'Kaos Kaki', 'Box Sepatu'];

    private const TAS = [
        'Dust Bag', 'Care Card/Card', 'Tali panjang', 'Tali pendek',
        'Tag Brand', 'Price tag', 'Receipt',
    ];

    /** @return list<string> */
    public static function labels(int $type): array
    {
        return match ($type) {
            1 => self::TAS,
            2 => self::SEPATU,
            default => [],
        };
    }

    /**
     * Bentuk simpan: "true, false, true" — koma spasi, persis seperti admin panel.
     *
     * @param  array<int, mixed>  $flags
     */
    public static function serialize(int $type, array $flags): string
    {
        $hasil = [];

        foreach (self::labels($type) as $index => $label) {
            $hasil[] = ! empty($flags[$index]) ? 'true' : 'false';
        }

        return implode(', ', $hasil);
    }

    /**
     * Nama kelengkapan yang tercentang — untuk dibaca manusia.
     *
     * @return list<string>
     */
    public static function checked(OrderItem $item): array
    {
        $flags = self::flags($item);
        $hasil = [];

        foreach (self::labels((int) $item->type) as $index => $label) {
            if ($flags[$index] ?? false) {
                $hasil[] = $label;
            }
        }

        return $hasil;
    }

    /**
     * Bentuk mentahnya — untuk mengisi ulang formulir.
     *
     * @return list<bool>
     */
    public static function flags(OrderItem $item): array
    {
        $tersimpan = $item->checkbox
            ? array_map(fn ($nilai) => trim($nilai) === 'true', explode(',', $item->checkbox))
            : [];

        $hasil = [];

        foreach (array_keys(self::labels((int) $item->type)) as $index) {
            $hasil[] = $tersimpan[$index] ?? false;
        }

        return $hasil;
    }
}
