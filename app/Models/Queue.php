<?php
// File: app/Models/Queue.php
// PERBAIKAN LENGKAP untuk Queue Model dengan relationship dokter yang benar

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class Queue extends Model
{
    protected $fillable = [
        'counter_id',
        'service_id',
        'user_id',
        'doctor_id',  // ✅ TAMBAH doctor_id ke fillable
        'number',
        'status',
        'called_at',
        'served_at',
        'canceled_at',
        'finished_at',
    ];

    protected $casts = [
        'called_at' => 'datetime',
        'served_at' => 'datetime', 
        'canceled_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    // ✅ RELATIONSHIP YANG BENAR
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(Counter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ✅ PERBAIKAN UTAMA: Tambah relationship ke DoctorSchedule
    public function doctorSchedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class, 'doctor_id');
    }

    public function medicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class);
    }

    // ✅ ACCESSOR METHODS YANG BENAR

    /**
     * ✅ PERBAIKAN: Get doctor name dari doctor_id (DoctorSchedule) atau Medical Record
     */
    public function getDoctorNameAttribute(): ?string
    {
        // Prioritas 1: Ambil dari doctor_id yang dipilih saat antrian
        if ($this->doctor_id && $this->doctorSchedule) {
            return $this->doctorSchedule->doctor_name;
        }
        
        // Prioritas 2: Ambil dari medical record jika ada
        if ($this->medicalRecord && $this->medicalRecord->doctor) {
            return $this->medicalRecord->doctor->name;
        }
        
        return null;
    }

    /**
     * ✅ PERBAIKAN: Get poli name dari service relationship
     */
    public function getPoliAttribute(): ?string
    {
        return $this->service->name ?? null;
    }

    /**
     * ✅ PERBAIKAN: Get patient name dari user relationship
     */
    public function getNameAttribute(): ?string
    {
        return $this->user->name ?? null;
    }

    /**
     * ✅ PERBAIKAN: Get patient phone dari user relationship
     */
    public function getPhoneAttribute(): ?string
    {
        return $this->user->phone ?? null;
    }

    /**
     * ✅ PERBAIKAN: Get patient gender dari user relationship
     */
    public function getGenderAttribute(): ?string
    {
        return $this->user->gender ?? null;
    }

    /**
     * Get status badge color untuk UI
     */
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'waiting' => 'warning',
            'serving' => 'info', 
            'finished' => 'success',
            'canceled' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Get status dalam bahasa Indonesia
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'waiting' => 'Menunggu',
            'serving' => 'Sedang Dilayani',
            'finished' => 'Selesai',
            'canceled' => 'Dibatalkan',
            default => ucfirst($this->status)
        };
    }

    /**
     * Get tanggal antrian dalam format yang mudah dibaca
     */
    public function getFormattedTanggalAttribute(): string
    {
        return $this->created_at->format('d F Y');
    }

    // ✅ HELPER METHODS

    /**
     * Check apakah antrian bisa diedit
     */
    public function canEdit(): bool
    {
        return in_array($this->status, ['waiting']);
    }

    /**
     * Check apakah antrian bisa dibatalkan
     */
    public function canCancel(): bool
    {
        return in_array($this->status, ['waiting']);
    }

    /**
     * Check apakah antrian bisa diprint
     */
    public function canPrint(): bool
    {
        return in_array($this->status, ['waiting', 'serving', 'finished']);
    }

    /**
     * Check apakah antrian sudah selesai atau dibatalkan
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['finished', 'canceled']);
    }

    // ✅ SCOPE METHODS

    /**
     * Scope untuk antrian hari ini
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Scope untuk antrian berdasarkan user tertentu
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope untuk antrian berdasarkan service/poli
     */
    public function scopeForService($query, $serviceName)
    {
        return $query->whereHas('service', function($q) use ($serviceName) {
            $q->where('name', $serviceName);
        });
    }

    /**
     * Scope untuk antrian berdasarkan status
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}