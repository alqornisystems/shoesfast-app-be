<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Apakah pengguna request ini seorang admin?
     *
     * Dipakai untuk memisahkan "melihat semuanya" dari "melihat pekerjaan sendiri". Sengaja
     * memakai NAMA JABATAN, bukan `projects_id === null` yang di tempat lain diberi nama
     * `is_super_admin` — flag itu artinya "belum ditugaskan ke cabang", dan 13 dari 16 teknisi
     * memenuhi syarat itu. Memakainya di sini akan membuat mereka melihat pekerjaan semua orang.
     */
    protected function isAdmin(Request $request): bool
    {
        $role = $request->user()?->role?->name;

        return $role !== null
            && in_array(strtolower(trim($role)), ['admin', 'admin super'], true);
    }
}
