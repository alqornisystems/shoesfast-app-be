<?php

namespace Tests\Unit;

use App\Support\MapsPoint;
use PHPUnit\Framework\TestCase;

/**
 * Parser URL Google Maps → koordinat.
 *
 * Bentuk yang nyata ada di produksi: 3.913 dari 3.916 baris `customers.maps`
 * memuat `@lat,lng`, 173 di antaranya memakai domain google.co.id. Yang tiga
 * sisanya bukan URL peta sama sekali.
 */
class MapsPointTest extends TestCase
{
    public function test_membaca_koordinat_dari_url_place(): void
    {
        $titik = MapsPoint::fromUrl(
            'https://www.google.com/maps/place/Citraland+Surabaya/@-7.28848,112.6331921,17z/data=!3m1!4b1'
        );

        $this->assertNotNull($titik);
        $this->assertSame(-7.28848, $titik['latitude']);
        $this->assertSame(112.6331921, $titik['longitude']);
    }

    public function test_membaca_domain_google_co_id(): void
    {
        $titik = MapsPoint::fromUrl(
            'https://www.google.co.id/maps/place/Giri+Palma+Mebel/@-7.9553004,112.5873766,17z'
        );

        $this->assertSame(-7.9553004, $titik['latitude']);
        $this->assertSame(112.5873766, $titik['longitude']);
    }

    public function test_zoom_tidak_tertukar_jadi_bujur(): void
    {
        $titik = MapsPoint::fromUrl('https://maps.google.com/maps/@-7.1,112.2,21z');

        $this->assertSame(112.2, $titik['longitude']);
    }

    public function test_koordinat_bulat_tanpa_desimal_diterima(): void
    {
        $titik = MapsPoint::fromUrl('https://www.google.com/maps/@-7,112,17z');

        $this->assertSame(-7.0, $titik['latitude']);
        $this->assertSame(112.0, $titik['longitude']);
    }

    public function test_nama_tempat_berisi_at_tidak_mengecoh(): void
    {
        $titik = MapsPoint::fromUrl(
            'https://www.google.com/maps/place/Kopi+@+Rumah/@-7.3,112.7,17z'
        );

        $this->assertSame(-7.3, $titik['latitude']);
    }

    /**
     * Review Focus 1: format share lain yang menaruh titiknya di !3d/!4d.
     * Tidak dibaca — dan harus dilaporkan sebagai tidak ada titik, bukan
     * setengah titik atau galat.
     */
    public function test_url_tanpa_at_latlng_dianggap_tidak_ada_titik(): void
    {
        $this->assertNull(MapsPoint::fromUrl(
            'https://www.google.com/maps/place/Toko/data=!3m1!4b1!3d-7.9!4d112.5'
        ));
    }

    public function test_url_pencarian_tanpa_koordinat_dianggap_tidak_ada_titik(): void
    {
        $this->assertNull(MapsPoint::fromUrl(
            'https://www.google.com/maps/search/Jalan+Mojo+3'
        ));
    }

    /** Review Focus 2: 0,0 lolos rentang tapi menunjuk Teluk Guinea. */
    public function test_nol_nol_ditolak(): void
    {
        $this->assertNull(MapsPoint::fromUrl('https://www.google.com/maps/@0,0,17z'));
    }

    public function test_lintang_di_luar_rentang_ditolak(): void
    {
        $this->assertNull(MapsPoint::fromUrl('https://www.google.com/maps/@-91.5,112.5,17z'));
    }

    public function test_bujur_di_luar_rentang_ditolak(): void
    {
        $this->assertNull(MapsPoint::fromUrl('https://www.google.com/maps/@-7.5,181.2,17z'));
    }

    public function test_bukan_url_dan_kosong_dianggap_tidak_ada_titik(): void
    {
        $this->assertNull(MapsPoint::fromUrl('depan lapangan, pagar hijau'));
        $this->assertNull(MapsPoint::fromUrl(''));
        $this->assertNull(MapsPoint::fromUrl(null));
    }
}
