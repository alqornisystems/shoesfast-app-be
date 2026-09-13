<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait, HasApiTokens;

    protected $table = 'customers';

    protected $dateFormat = 'U';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id',
        'name',
        'phone',
        'address',
        'email',
        'instagram',
        'photo',
        'maps',
        'date_of_birth',
        'hobby',
        'favorite_food',
        'behavior',
        'is_member',
        'member_code',
        'member_since',
        'points',
        'latitude',
        'longitude',
        'pin',
        'pin_created_at',
        'pin_created_ip',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    // PIN tidak pernah ikut dalam respons mana pun.
    protected $hidden = ['pin'];

    protected $casts = [
        'date_of_birth' => 'integer',
        'created_at' => 'integer',
        'modified_at' => 'integer',
        'is_deleted' => 'integer',
        'is_member' => 'integer',
        'points' => 'integer',
        'pin_created_at' => 'integer',
    ];

    /**
     * `created_at`/`modified_at` di JSON tetap detik unix seperti di database.
     *
     * Bawaan Laravel mengubah kolom timestamp jadi teks ISO lebih dulu, lalu cast
     * `integer` di atas memotong teks itu menjadi tahunnya saja — `2024`, yang
     * dibaca layar sebagai 1 Januari 1970.
     */
    protected function serializeDate(\DateTimeInterface $date): int
    {
        return $date->getTimestamp();
    }

    /**
     * Kolom kata sandi tabel ini bernama `pin`, bukan `password`.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->pin;
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', function ($query) {
            $query->where('is_deleted', 0);
        });
    }

    /**
     * Override delete to perform soft delete
     */
    public function delete()
    {
        $this->is_deleted = 1;

        return $this->save();
    }

    /**
     * Force delete (actual deletion)
     */
    public function forceDelete()
    {
        return parent::delete();
    }

    /**
     * Customer bisa terdaftar di banyak cabang (many-to-many)
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'customer_project',
            'customers_id',
            'projects_id'
        );
    }

    /**
     * Relationship to Orders
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'customers_id');
    }

    /**
     * Cabang tempat pelanggan terdaftar. Berbeda dari projects() yang
     * many-to-many; ini kolom projects_id yang dipakai untuk cakupan data.
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class, 'customers_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(CustomerTag::class, 'customers_id');
    }

    /** Label tiap segmen, dalam urutan tampil. */
    public const SEGMEN = [
        'vip' => 'VIP',
        'loyal' => 'Loyal',
        'aktif' => 'Aktif',
        'baru' => 'Baru',
        'mau_hilang' => 'Mau Hilang',
        'tidur' => 'Tidur',
        'belum_order' => 'Belum Order',
    ];

    /**
     * Tempelkan ringkasan belanja (alias tabel `rb`) dan segmen ke tiap pelanggan.
     *
     * Dihitung saat diminta, tidak disimpan: kolom yang disimpan basi begitu ada
     * pesanan baru, dan dengan beberapa ribu pelanggan hitungannya murah.
     * Pesanan dari SEMUA cabang dihitung — pelanggan dipakai bersama seluruh
     * cabang, jadi segmennya pun milik pelanggan, bukan milik satu cabang.
     *
     * ponytail: agregat penuh tiap permintaan; simpan ke tabel ringkasan kalau
     * pesanan sudah ratusan ribu baris.
     */
    public function scopeDenganRingkasanBelanja(Builder $query): Builder
    {
        $ringkasan = DB::table('orders')
            ->selectRaw('customers_id, COUNT(*) AS jumlah_pesanan, SUM(total_price) AS total_belanja, '
                .'MIN(date) AS pesanan_pertama, MAX(date) AS pesanan_terakhir')
            ->where('is_deleted', 0)
            ->groupBy('customers_id');

        return $query
            ->leftJoinSub($ringkasan, 'rb', 'rb.customers_id', '=', 'customers.id')
            ->addSelect([
                'customers.*',
                DB::raw('COALESCE(rb.jumlah_pesanan, 0) AS jumlah_pesanan'),
                DB::raw('COALESCE(rb.total_belanja, 0) AS total_belanja'),
                'rb.pesanan_pertama',
                'rb.pesanan_terakhir',
                DB::raw(self::sqlSegmen().' AS segmen'),
            ]);
    }

    /**
     * Batas segmen yang sedang berlaku. Satu sumber untuk rumus segmen dan untuk
     * teks penjelasan di Dashboard CRM, supaya keterangannya tidak basi saat
     * batasnya diubah lewat `settings`.
     *
     * @return array{days_at_risk: int, days_dormant: int, min_spent_vip: int, min_orders_loyal: int}
     */
    public static function batasSegmen(): array
    {
        return [
            'days_at_risk' => (int) Setting::read('crm_hari_mau_hilang', 60),
            'days_dormant' => (int) Setting::read('crm_hari_tidur', 120),
            'min_spent_vip' => (int) Setting::read('crm_min_belanja_vip', 2000000),
            'min_orders_loyal' => (int) Setting::read('crm_min_pesanan_loyal', 5),
        ];
    }

    /**
     * Segmen sebagai ekspresi SQL atas tabel `rb`, supaya bisa dipakai untuk
     * menyaring, mengurutkan, dan menghitung tanpa menarik semua pelanggan.
     *
     * Kebaruan dinilai lebih dulu: pelanggan VIP yang empat bulan tidak datang
     * masuk "Tidur", karena yang perlu dilakukan terhadapnya adalah menyapanya
     * kembali. Batasnya bisa diubah lewat tabel `settings`.
     */
    public static function sqlSegmen(): string
    {
        $batas = self::batasSegmen();
        $sekarang = time();
        $batasTidur = $sekarang - $batas['days_dormant'] * 86400;
        $batasMauHilang = $sekarang - $batas['days_at_risk'] * 86400;
        $minVip = $batas['min_spent_vip'];
        $minLoyal = $batas['min_orders_loyal'];

        return "CASE
            WHEN rb.jumlah_pesanan IS NULL THEN 'belum_order'
            WHEN rb.pesanan_terakhir < {$batasTidur} THEN 'tidur'
            WHEN rb.pesanan_terakhir < {$batasMauHilang} THEN 'mau_hilang'
            WHEN rb.total_belanja >= {$minVip} THEN 'vip'
            WHEN rb.jumlah_pesanan >= {$minLoyal} THEN 'loyal'
            WHEN rb.jumlah_pesanan = 1 THEN 'baru'
            ELSE 'aktif' END";
    }
}
