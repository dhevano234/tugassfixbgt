<?php
// File: app/Models/Queue.php - FINAL VERSION

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
        'chief_complaint',
        'estimated_call_time', // ✅ ESTIMASI WAKTU PANGGILAN
        'extra_delay_minutes', // ✅ EXTRA DELAY 5 MENIT
        'called_at',
        'served_at',
        'canceled_at',
        'finished_at',
    ];

    protected $casts = [
        'tanggal_antrian' => 'date',
        'estimated_call_time' => 'datetime', // ✅ CAST DATETIME
        'called_at' => 'datetime',
        'served_at' => 'datetime', 
        'canceled_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ RELATIONSHIPS
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

    public function doctorSchedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class, 'doctor_id');
    }

    public function medicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class);
    }

    // ✅ EXISTING ACCESSORS
    public function getDoctorNameAttribute(): ?string
    {
        if ($this->doctor_id && $this->doctorSchedule) {
            return $this->doctorSchedule->doctor_name;
        }
        
        if ($this->medicalRecord && $this->medicalRecord->doctor) {
            return $this->medicalRecord->doctor->name;
        }
        
        return null;
    }

    public function hasChiefComplaint(): bool
    {
        return !empty($this->chief_complaint);
    }

    public function getFormattedChiefComplaintAttribute(): ?string
    {
        return empty($this->chief_complaint) ? null : $this->chief_complaint;
    }

    public function getShortComplaintAttribute(): ?string
    {
        if (empty($this->chief_complaint)) {
            return null;
        }
        
        return strlen($this->chief_complaint) > 50 
            ? substr($this->chief_complaint, 0, 50) . '...'
            : $this->chief_complaint;
    }

    public function getPoliAttribute(): ?string
    {
        return $this->service->name ?? null;
    }

    public function getNameAttribute(): ?string
    {
        return $this->user->name ?? null;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->user->phone ?? null;
    }

    public function getGenderAttribute(): ?string
    {
        return $this->user->gender ?? null;
    }

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

    public function getFormattedTanggalAttribute(): string
    {
        return $this->created_at->setTimezone('Asia/Jakarta')->format('d F Y');
    }

    public function getWaktuAmbilAttribute(): string
    {
        return $this->created_at->setTimezone('Asia/Jakarta')->format('H:i');
    }

    public function getWaktuDipanggilAttribute(): ?string
    {
        return $this->called_at ? $this->called_at->setTimezone('Asia/Jakarta')->format('H:i') : null;
    }

    public function getWaktuSelesaiAttribute(): ?string
    {
        return $this->finished_at ? $this->finished_at->setTimezone('Asia/Jakarta')->format('H:i') : null;
    }

    public function getWaktuDilayaniAttribute(): ?string
    {
        return $this->served_at ? $this->served_at->setTimezone('Asia/Jakarta')->format('H:i') : null;
    }

    public function getWaktuDibatalkanAttribute(): ?string
    {
        return $this->canceled_at ? $this->canceled_at->setTimezone('Asia/Jakarta')->format('H:i') : null;
    }

    public function getFullDateTimeAttribute(): string
    {
        return $this->created_at->setTimezone('Asia/Jakarta')->format('l, d F Y H:i');
    }

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

    // ✅ ESTIMASI WAKTU TUNGGU ACCESSORS - FINAL VERSION
    
    /**
     * ✅ Get estimasi waktu tunggu dalam menit (INTEGER BERSIH)
     */
    public function getEstimasiTungguAttribute(): ?int
    {
        if ($this->status !== 'waiting') {
            return null;
        }
        
        // Jika ada estimated_call_time, gunakan itu
        if ($this->estimated_call_time) {
            $now = now();
            $estimatedTime = $this->estimated_call_time;
            
            if ($estimatedTime > $now) {
                // Masih dalam estimasi normal
                $diffMinutes = $now->diffInMinutes($estimatedTime);
                return (int) round($diffMinutes); // ✅ INTEGER BERSIH
            } else {
                // Sudah lewat estimasi, pakai extra delay
                return (int) ($this->extra_delay_minutes ?: 5);
            }
        }
        
        // ✅ FALLBACK: Kalau belum ada estimated_call_time, hitung manual
        $antrianDidepan = self::where('service_id', $this->service_id)
            ->where('status', 'waiting')
            ->where('id', '<', $this->id)
            ->whereDate('created_at', today())
            ->count();
        
        return ($antrianDidepan + 1) * 15; // 15 menit per antrian
    }

    /**
     * ✅ Get estimasi waktu panggilan yang sudah diformat
     */
    public function getFormattedEstimasiAttribute(): ?string
    {
        if ($this->status !== 'waiting') {
            return null;
        }

        $estimasiMenit = $this->estimasi_tunggu; // Pakai accessor yang sudah diperbaiki
        
        if ($estimasiMenit < 1) {
            return "Segera dipanggil";
        } elseif ($estimasiMenit < 60) {
            return "~{$estimasiMenit} menit lagi";
        } else {
            $hours = floor($estimasiMenit / 60);
            $minutes = $estimasiMenit % 60;
            return "~{$hours}j {$minutes}m lagi";
        }
    }

    /**
     * ✅ Get status delay (on_time, delayed)
     */
    public function getDelayStatusAttribute(): string
    {
        if ($this->status !== 'waiting') {
            return 'unknown';
        }

        if (!$this->estimated_call_time) {
            return 'on_time'; // Default jika belum ada estimasi
        }

        $now = now();
        $estimatedTime = $this->estimated_call_time;
        
        return $estimatedTime > $now ? 'on_time' : 'delayed';
    }

    /**
     * ✅ Get posisi dalam antrian
     */
    public function getQueuePositionAttribute(): int
    {
        return self::where('service_id', $this->service_id)
            ->where('status', 'waiting')
            ->where('id', '<', $this->id)
            ->whereDate('created_at', today())
            ->count() + 1;
    }

    /**
     * ✅ Get estimasi waktu panggilan dalam format jam
     */
    public function getEstimatedCallTimeFormattedAttribute(): ?string
    {
        if (!$this->estimated_call_time) {
            // FALLBACK: Hitung estimasi manual
            $estimasiMenit = $this->estimasi_tunggu;
            $estimatedTime = $this->created_at->addMinutes($estimasiMenit);
            return $estimatedTime->setTimezone('Asia/Jakarta')->format('H:i');
        }

        return $this->estimated_call_time->setTimezone('Asia/Jakarta')->format('H:i');
    }

    /**
     * ✅ Check apakah antrian sudah terlambat dari estimasi
     */
    public function getIsOverdueAttribute(): bool
    {
        if ($this->status !== 'waiting') {
            return false;
        }

        if (!$this->estimated_call_time) {
            // FALLBACK: Hitung berdasarkan created_at + estimasi
            $estimasiMenit = $this->estimasi_tunggu;
            $estimatedTime = $this->created_at->addMinutes($estimasiMenit);
            return $estimatedTime < now();
        }

        return $this->estimated_call_time < now();
    }

    // ✅ HELPER METHODS
    public function canEdit(): bool
    {
        return in_array($this->status, ['waiting']);
    }

    public function canCancel(): bool
    {
        return in_array($this->status, ['waiting']);
    }

    public function canPrint(): bool
    {
        return in_array($this->status, ['waiting', 'serving', 'finished']);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['finished', 'canceled']);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['waiting', 'serving']);
    }

    // ✅ SCOPE METHODS
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForService($query, $serviceName)
    {
        return $query->whereHas('service', function($q) use ($serviceName) {
            $q->where('name', $serviceName);
        });
    }

    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['waiting', 'serving']);
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['finished', 'canceled']);
    }

    public function scopeWithComplaint($query)
    {
        return $query->whereNotNull('chief_complaint')
                    ->where('chief_complaint', '!=', '');
    }

    public function scopeWithoutComplaint($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('chief_complaint')
              ->orWhere('chief_complaint', '');
        });
    }

    // ✅ SCOPES untuk estimasi waktu
    public function scopeOverdue($query)
    {
        return $query->where('status', 'waiting')
                    ->where('estimated_call_time', '<', now());
    }

    public function scopeOnTime($query)
    {
        return $query->where('status', 'waiting')
                    ->where('estimated_call_time', '>=', now());
    }
}