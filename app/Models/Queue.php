<?php
// File: app/Models/Queue.php
// PERBAIKAN LENGKAP untuk Queue Model dengan chief_complaint support

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
        'doctor_id',
        'number',
        'status',
        'tanggal_antrian',
        'chief_complaint', // ✅ TAMBAH INI - untuk keluhan dari antrian
        'called_at',
        'served_at',
        'canceled_at',
        'finished_at',
    ];

    protected $casts = [
        'tanggal_antrian' => 'date',
        'called_at' => 'datetime',
        'served_at' => 'datetime', 
        'canceled_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
     * ✅ TAMBAHAN: Check apakah ada keluhan dari antrian
     */
    public function hasChiefComplaint(): bool
    {
        return !empty($this->chief_complaint);
    }

    /**
     * ✅ TAMBAHAN: Get keluhan yang sudah diformat
     */
    public function getFormattedChiefComplaintAttribute(): ?string
    {
        if (empty($this->chief_complaint)) {
            return null;
        }
        
        return $this->chief_complaint;
    }

    /**
     * ✅ TAMBAHAN: Get short complaint untuk preview
     */
    public function getShortComplaintAttribute(): ?string
    {
        if (empty($this->chief_complaint)) {
            return null;
        }
        
        return strlen($this->chief_complaint) > 50 
            ? substr($this->chief_complaint, 0, 50) . '...'
            : $this->chief_complaint;
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
     * ✅ PERBAIKAN TIMEZONE: Get tanggal antrian dalam format yang mudah dibaca dengan timezone Indonesia
     */
    public function getFormattedTanggalAttribute(): string
    {
        return $this->created_at->setTimezone('Asia/Jakarta')->format('d F Y');
    }

    /**
     * ✅ TAMBAHAN: Accessor untuk waktu ambil antrian dengan timezone Indonesia
     */
    public function getWaktuAmbilAttribute(): string
    {
        return $this->created_at->setTimezone('Asia/Jakarta')->format('H:i');
    }

    /**
     * ✅ TAMBAHAN: Accessor untuk waktu dipanggil dengan timezone Indonesia
     */
    public function getWaktuDipanggilAttribute(): ?string
    {
        return $this->called_at ? $this->called_at->setTimezone('Asia/Jakarta')->format('H:i') : null;
    }

    /**
     * ✅ TAMBAHAN: Accessor untuk waktu selesai dengan timezone Indonesia
     */
    public function getWaktuSelesaiAttribute(): ?string
    {
        return $this->finished_at ? $this->finished_at->setTimezone('Asia/Jakarta')->format('H:i') : null;
    }

    /**
     * ✅ TAMBAHAN: Accessor untuk waktu dilayani dengan timezone Indonesia
     */
    public function getWaktuDilayaniAttribute(): ?string
    {
        return $this->served_at ? $this->served_at->setTimezone('Asia/Jakarta')->format('H:i') : null;
    }

    /**
     * ✅ TAMBAHAN: Accessor untuk waktu dibatalkan dengan timezone Indonesia
     */
    public function getWaktuDibatalkanAttribute(): ?string
    {
        return $this->canceled_at ? $this->canceled_at->setTimezone('Asia/Jakarta')->format('H:i') : null;
    }

    /**
     * ✅ TAMBAHAN: Accessor untuk datetime lengkap dengan timezone Indonesia
     */
    public function getFullDateTimeAttribute(): string
    {
        return $this->created_at->setTimezone('Asia/Jakarta')->format('l, d F Y H:i');
    }

    /**
     * ✅ TAMBAHAN: Get informasi timeline antrian
     */
    public function getTimelineInfoAttribute(): array
    {
        $timeline = [];
        
        $timeline['dibuat'] = [
            'waktu' => $this->waktu_ambil,
            'status' => 'Antrian dibuat',
            'icon' => 'fas fa-plus-circle',
            'color' => 'primary'
        ];
        
        if ($this->called_at) {
            $timeline['dipanggil'] = [
                'waktu' => $this->waktu_dipanggil,
                'status' => 'Antrian dipanggil',
                'icon' => 'fas fa-bell',
                'color' => 'warning'
            ];
        }
        
        if ($this->served_at) {
            $timeline['dilayani'] = [
                'waktu' => $this->waktu_dilayani,
                'status' => 'Mulai dilayani',
                'icon' => 'fas fa-user-md',
                'color' => 'info'
            ];
        }
        
        if ($this->finished_at) {
            $timeline['selesai'] = [
                'waktu' => $this->waktu_selesai,
                'status' => 'Selesai dilayani',
                'icon' => 'fas fa-check-circle',
                'color' => 'success'
            ];
        }
        
        if ($this->canceled_at) {
            $timeline['dibatalkan'] = [
                'waktu' => $this->waktu_dibatalkan,
                'status' => 'Antrian dibatalkan',
                'icon' => 'fas fa-times-circle',
                'color' => 'danger'
            ];
        }
        
        return $timeline;
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

    /**
     * ✅ TAMBAHAN: Check apakah antrian sedang aktif (menunggu atau dilayani)
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['waiting', 'serving']);
    }

    /**
     * ✅ TAMBAHAN: Get estimasi waktu tunggu (dalam menit)
     */
    public function getEstimasiTungguAttribute(): ?int
    {
        if ($this->status !== 'waiting') {
            return null;
        }
        
        // Hitung antrian yang ada di depan
        $antrianDidepan = self::where('service_id', $this->service_id)
            ->where('status', 'waiting')
            ->where('created_at', '<', $this->created_at)
            ->count();
        
        // Estimasi 15 menit per antrian
        return $antrianDidepan * 15;
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

    /**
     * ✅ TAMBAHAN: Scope untuk antrian yang aktif (waiting atau serving)
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['waiting', 'serving']);
    }

    /**
     * ✅ TAMBAHAN: Scope untuk antrian yang selesai
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['finished', 'canceled']);
    }

    /**
     * ✅ TAMBAHAN: Scope untuk antrian yang memiliki keluhan
     */
    public function scopeWithComplaint($query)
    {
        return $query->whereNotNull('chief_complaint')
                    ->where('chief_complaint', '!=', '');
    }

    /**
     * ✅ TAMBAHAN: Scope untuk antrian tanpa keluhan
     */
    public function scopeWithoutComplaint($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('chief_complaint')
              ->orWhere('chief_complaint', '');
        });
    }
}