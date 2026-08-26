<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pengurutan pilihan pengguna untuk endpoint daftar.
 *
 * Kolomnya SELALU diambil dari daftar putih milik controller: nilai `sort` dari request
 * dipakai sebagai KUNCI, dan yang masuk ke SQL adalah ekspresi yang ditulis di kode.
 * Tidak ada string dari request yang pernah menyentuh query.
 *
 * `reorder()` membuang urutan bawaan endpoint lebih dulu — tanpa itu pilihan pengguna
 * hanya jadi pemecah seri di belakang urutan aslinya, dan tampak tidak berpengaruh.
 */
trait DaftarTersortir
{
    /** @param array<string,string> $daftarPutih */
    protected function aturanSortir(array $daftarPutih): array
    {
        return [
            'sort' => ['nullable', 'string', Rule::in(array_keys($daftarPutih))],
            'order' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /** @param array<string,string> $daftarPutih */
    protected function terapkanSortir(Builder $query, Request $request, array $daftarPutih, string $kunciUtama): Builder
    {
        if (! $request->filled('sort') || ! isset($daftarPutih[$request->input('sort')])) {
            return $query;
        }

        $arah = strtolower($request->input('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        return $query->reorder()
            ->orderBy(DB::raw($daftarPutih[$request->input('sort')]), $arah)
            ->orderBy($kunciUtama, $arah);
    }
}
