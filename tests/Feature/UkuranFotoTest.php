<?php

namespace Tests\Feature;

use App\Support\Base64Image;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Penyesuaian ukuran foto unggahan: lebar 1080px, tinggi mengikuti rasio.
 *
 * Dipakai gambar sungguhan yang dibuat GD, bukan string palsu — yang diuji di sini
 * justru dimensi hasilnya, dan itu tidak bisa dibuktikan dengan muatan tiruan.
 */
class UkuranFotoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Ekstensi GD tidak tersedia.');
        }
    }

    /** Data URL berisi gambar sungguhan berukuran tertentu. */
    private function gambar(int $lebar, int $tinggi, string $jenis = 'jpg'): string
    {
        $img = imagecreatetruecolor($lebar, $tinggi);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 60));

        ob_start();
        $jenis === 'png' ? imagepng($img) : imagejpeg($img, null, 90);
        $isi = ob_get_clean();
        imagedestroy($img);

        $mime = $jenis === 'png' ? 'png' : 'jpeg';

        return "data:image/{$mime};base64,".base64_encode($isi);
    }

    /** @return array{0: int, 1: int} */
    private function dimensi(string $jalur): array
    {
        $ukuran = getimagesizefromstring(Storage::disk('public')->get($jalur));

        return [$ukuran[0], $ukuran[1]];
    }

    public function test_foto_lebar_dikecilkan_ke_1080_dengan_rasio_terjaga(): void
    {
        $jalur = Base64Image::store($this->gambar(3000, 2000), 'users');

        [$lebar, $tinggi] = $this->dimensi($jalur);

        $this->assertSame(1080, $lebar);
        // 3000x2000 → rasio 1,5 → 1080x720.
        $this->assertSame(720, $tinggi);
    }

    public function test_foto_potret_juga_dipatok_lebarnya(): void
    {
        $jalur = Base64Image::store($this->gambar(2000, 3000), 'sends');

        [$lebar, $tinggi] = $this->dimensi($jalur);

        $this->assertSame(1080, $lebar);
        $this->assertSame(1620, $tinggi);
    }

    /** Membesarkan hanya menambah berkas tanpa menambah detail, dan hasilnya lebih buram. */
    public function test_gambar_yang_sudah_kecil_tidak_dibesarkan(): void
    {
        $jalur = Base64Image::store($this->gambar(400, 300), 'users');

        [$lebar, $tinggi] = $this->dimensi($jalur);

        $this->assertSame(400, $lebar);
        $this->assertSame(300, $tinggi);
    }

    /** PNG buram dari kamera/tangkapan layar jauh lebih besar tanpa manfaat apa pun. */
    public function test_png_tanpa_transparansi_dikeluarkan_sebagai_jpg(): void
    {
        $jalur = Base64Image::store($this->gambar(2000, 1000, 'png'), 'absences');

        $this->assertStringEndsWith('.jpg', $jalur);
        $this->assertSame(1080, $this->dimensi($jalur)[0]);
    }

    public function test_hasilnya_jauh_lebih_kecil_dari_aslinya(): void
    {
        $asli = $this->gambar(3000, 2000);
        $jalur = Base64Image::store($asli, 'users');

        $besarAsli = strlen(base64_decode(substr($asli, strpos($asli, ',') + 1)));
        $besarBaru = strlen(Storage::disk('public')->get($jalur));

        $this->assertLessThan($besarAsli, $besarBaru);
    }

    /** Nilai yang bukan data URL harus lewat tanpa disentuh saat DISIMPAN. */
    public function test_jalur_lama_dan_url_absolut_dibiarkan(): void
    {
        $this->assertSame('users/lama.jpg', Base64Image::store('users/lama.jpg', 'users'));

        // Saat menyimpan, URL lama tidak disentuh: tidak ada berkas asli untuk diproses
        // ulang. Penggantian domainnya terjadi saat DIBACA — lihat test di bawah.
        $this->assertSame(
            'https://app.shoesfastind.com/img/customers/customer-1749096040.png',
            Base64Image::store('https://app.shoesfastind.com/img/customers/customer-1749096040.png', 'users')
        );
    }

    /**
     * Domain lama sudah MATI — tidak resolve sama sekali, bukan 404 — sedangkan berkasnya
     * terlayani di domain sekarang. Tanpa penggantian ini setiap foto warisan (kurir,
     * pelanggan, barang) tampil rusak di semua aplikasi sekaligus.
     */
    public function test_url_domain_lama_diganti_domain_sekarang(): void
    {
        $this->assertSame(
            'https://app.shoesfast.id/img/couriers/courier-1667444269.png',
            Base64Image::url('https://app.shoesfastind.com/img/couriers/courier-1667444269.png')
        );
    }

    public function test_url_domain_lain_tidak_disentuh(): void
    {
        $this->assertSame(
            'https://cdn.contoh.com/x.png',
            Base64Image::url('https://cdn.contoh.com/x.png')
        );
    }

    /**
     * Layar edit menyeed formulirnya dengan URL yang tadi dibaca, lalu mengirimkannya
     * kembali saat simpan. Kalau ditelan bulat-bulat, kolomnya berubah dari jalur jadi
     * URL utuh — dan domain yang tertulis di database persis masalah yang membuat API
     * lama harus menambal tiap pembacaan dengan str_replace saat domain pindah.
     */
    public function test_url_storage_sendiri_dikembalikan_jadi_jalur(): void
    {
        $urlSendiri = asset('storage/users/foto.jpg');

        $this->assertSame('users/foto.jpg', Base64Image::store($urlSendiri, 'users'));
    }

    public function test_url_menjadi_dan_kembali_dari_jalur_secara_konsisten(): void
    {
        $jalur = 'sends/bukti.jpg';

        $this->assertSame($jalur, Base64Image::store(Base64Image::url($jalur), 'sends'));
    }
}
