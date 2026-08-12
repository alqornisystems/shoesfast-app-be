<?php

namespace Tests\Feature;

use App\Support\FotoBase64;
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
        $jalur = FotoBase64::simpan($this->gambar(3000, 2000), 'users');

        [$lebar, $tinggi] = $this->dimensi($jalur);

        $this->assertSame(1080, $lebar);
        // 3000x2000 → rasio 1,5 → 1080x720.
        $this->assertSame(720, $tinggi);
    }

    public function test_foto_potret_juga_dipatok_lebarnya(): void
    {
        $jalur = FotoBase64::simpan($this->gambar(2000, 3000), 'sends');

        [$lebar, $tinggi] = $this->dimensi($jalur);

        $this->assertSame(1080, $lebar);
        $this->assertSame(1620, $tinggi);
    }

    /** Membesarkan hanya menambah berkas tanpa menambah detail, dan hasilnya lebih buram. */
    public function test_gambar_yang_sudah_kecil_tidak_dibesarkan(): void
    {
        $jalur = FotoBase64::simpan($this->gambar(400, 300), 'users');

        [$lebar, $tinggi] = $this->dimensi($jalur);

        $this->assertSame(400, $lebar);
        $this->assertSame(300, $tinggi);
    }

    /** PNG buram dari kamera/tangkapan layar jauh lebih besar tanpa manfaat apa pun. */
    public function test_png_tanpa_transparansi_dikeluarkan_sebagai_jpg(): void
    {
        $jalur = FotoBase64::simpan($this->gambar(2000, 1000, 'png'), 'absences');

        $this->assertStringEndsWith('.jpg', $jalur);
        $this->assertSame(1080, $this->dimensi($jalur)[0]);
    }

    public function test_hasilnya_jauh_lebih_kecil_dari_aslinya(): void
    {
        $asli = $this->gambar(3000, 2000);
        $jalur = FotoBase64::simpan($asli, 'users');

        $besarAsli = strlen(base64_decode(substr($asli, strpos($asli, ',') + 1)));
        $besarBaru = strlen(Storage::disk('public')->get($jalur));

        $this->assertLessThan($besarAsli, $besarBaru);
    }

    /** Nilai yang bukan data URL harus lewat tanpa disentuh. */
    public function test_jalur_lama_dan_url_absolut_dibiarkan(): void
    {
        $this->assertSame('users/lama.jpg', FotoBase64::simpan('users/lama.jpg', 'users'));
        $this->assertSame(
            'https://app.shoesfast.id/img/x.png',
            FotoBase64::simpan('https://app.shoesfast.id/img/x.png', 'users')
        );
    }
}
