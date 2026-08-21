<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Send extends Model
{
    use BranchScoped;

    protected $table = 'sends';

    protected $dateFormat = 'U'; // Unix timestamp

    protected $fillable = [
        'projects_id',
        'users_id',
        'orders_id',
        'orders_items_id',
        'date',
        'status',
        'type',
        'is_deleted',
        'created_by',
        'modified_by',
        // Alur tugas lapangan: berangkat, gagal/jadwal ulang, bukti serah terima.
        'started_at',
        'failed_at',
        'reason_code',
        'fail_note',
        'reschedule_date',
        'proof_photo',
        'receiver_name',
        'proof_latitude',
        'proof_longitude',
        'proof_at',
        // Sesi pelacakan pelanggan
        'tracking_token',
        'tracking_expires_at',
        'courier_latitude',
        'courier_longitude',
        'courier_accuracy',
        'courier_position_at',
    ];

    /**
     * Umur tautan pelacakan. Tugas yang lupa ditutup kurir tetap berhenti menyiarkan
     * posisinya setelah ini — batas waktu adalah penjaga terakhir kalau penutupan manual
     * tidak pernah terjadi.
     */
    const TRACKING_TTL_DETIK = 6 * 3600;

    /** Di bawah jarak ini kurir dianggap sudah sampai di depan alamat. */
    const RADIUS_TIBA_METER = 100;

    /**
     * Nilai kolom `status`. Ditulis sebagai konstanta karena angkanya tersebar di
     * belasan tempat dan sempat ditulis sebagai "0/1" telanjang di setiap query.
     * GAGAL adalah nilai baru — sebelumnya satu-satunya akhir sebuah tugas adalah
     * SELESAI, sehingga kegagalan hanya menggantung sebagai tugas berjalan.
     */
    const STATUS_BERJALAN = 0;

    const STATUS_SELESAI = 1;

    const STATUS_GAGAL = 2;

    protected $casts = [
        'date' => 'integer',
        'status' => 'integer',
        'type' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'created_by' => 'integer',
        'modified_by' => 'integer',
        'started_at' => 'integer',
        'failed_at' => 'integer',
        'reschedule_date' => 'integer',
        'proof_at' => 'integer',
        'tracking_expires_at' => 'integer',
        'courier_position_at' => 'integer',
    ];

    const UPDATED_AT = 'modified_at';

    /**
     * Boot method to add global scope
     */
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', function ($query) {
            $query->where('is_deleted', 0);
        });
    }

    /**
     * Relationship to User (kurir)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * Relationship to Order
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'orders_id');
    }

    /**
     * Relationship to OrderItem
     */
    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'orders_items_id');
    }

    /**
     * Relationship to Project/Branch
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }
}
