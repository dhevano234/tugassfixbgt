<?php
// File: app/Models/DailyQuota.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class DailyQuota extends Model
{
    protected $fillable = [
        'doctor_schedule_id',
        'quota_date',
        'total_quota',
        'used_quota',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'quota_date' => 'date',
        'is_active' => 'boolean',
        'total_quota' => 'integer',
        'used_quota' => 'integer',
    ];

    /**
     * Relationship ke Doctor Schedule
     */
    public function doctorSchedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class);
    }

    /**
     * Relationship ke Queue (antrian yang menggunakan kuota ini)
     */
    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class, 'doctor_id', 'doctor_schedule_id')
                    ->whereDate('tanggal_antrian', $this->quota_date);
    }

    /**
     * Get available quota (sisa kuota)
     */
    public function getAvailableQuotaAttribute(): int
    {
        return max(0, $this->total_quota - $this->used_quota);
    }

    /**
     * Get quota usage percentage
     */
    public function getUsagePercentageAttribute(): float
    {
        if ($this->total_quota == 0) return 0;
        return round(($this->used_quota / $this->total_quota) * 100, 1);
    }

    /**
     * Check if quota is full
     */
    public function isQuotaFull(): bool
    {
        return $this->used_quota >= $this->total_quota;
    }

    /**
     * Check if quota is nearly full (90% or more)
     */
    public function isQuotaNearlyFull(): bool
    {
        return $this->usage_percentage >= 90;
    }

    /**
     * Get status color for display
     */
    public function getStatusColorAttribute(): string
    {
        if ($this->isQuotaFull()) {
            return 'danger';
        } elseif ($this->isQuotaNearlyFull()) {
            return 'warning';
        } elseif ($this->used_quota > 0) {
            return 'success';
        } else {
            return 'gray';
        }
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        if ($this->isQuotaFull()) {
            return 'Penuh';
        } elseif ($this->isQuotaNearlyFull()) {
            return 'Hampir Penuh';
        } elseif ($this->used_quota > 0) {
            return 'Tersedia';
        } else {
            return 'Kosong';
        }
    }

    /**
     * Update used quota based on actual queue count
     */
    public function updateUsedQuota(): void
    {
        $actualUsed = Queue::where('doctor_id', $this->doctor_schedule_id)
            ->whereDate('tanggal_antrian', $this->quota_date)
            ->whereIn('status', ['waiting', 'serving', 'finished'])
            ->count();

        $this->update(['used_quota' => $actualUsed]);
    }

    /**
     * Increment used quota (when new queue is created)
     */
    public function incrementUsedQuota(): bool
    {
        if ($this->isQuotaFull()) {
            return false;
        }

        $this->increment('used_quota');
        return true;
    }

    /**
     * Decrement used quota (when queue is canceled)
     */
    public function decrementUsedQuota(): void
    {
        if ($this->used_quota > 0) {
            $this->decrement('used_quota');
        }
    }

    /**
     * Scope untuk quota aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope untuk tanggal tertentu
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('quota_date', $date);
    }

    /**
     * Scope untuk dokter tertentu
     */
    public function scopeForDoctor($query, $doctorScheduleId)
    {
        return $query->where('doctor_schedule_id', $doctorScheduleId);
    }

    /**
     * Scope untuk quota yang masih tersedia
     */
    public function scopeAvailable($query)
    {
        return $query->whereRaw('used_quota < total_quota');
    }

    /**
     * Scope untuk quota yang sudah penuh
     */
    public function scopeFull($query)
    {
        return $query->whereRaw('used_quota >= total_quota');
    }

    /**
     * Get or create quota for specific doctor and date
     */
    public static function getOrCreateQuota($doctorScheduleId, $date, $defaultQuota = 20): self
    {
        return static::firstOrCreate(
            [
                'doctor_schedule_id' => $doctorScheduleId,
                'quota_date' => $date,
            ],
            [
                'total_quota' => $defaultQuota,
                'used_quota' => 0,
                'is_active' => true,
            ]
        );
    }

    /**
     * Check if quota is available for booking
     */
    public static function isQuotaAvailable($doctorScheduleId, $date): bool
    {
        $quota = static::where('doctor_schedule_id', $doctorScheduleId)
            ->where('quota_date', $date)
            ->where('is_active', true)
            ->first();

        if (!$quota) {
            // Jika belum ada quota, anggap tersedia (akan dibuat otomatis)
            return true;
        }

        return !$quota->isQuotaFull();
    }

    /**
     * Get quota summary for specific date
     */
    public static function getQuotaSummaryForDate($date): array
    {
        $quotas = static::with('doctorSchedule.service')
            ->where('quota_date', $date)
            ->where('is_active', true)
            ->get();

        return [
            'total_doctors' => $quotas->count(),
            'total_quota' => $quotas->sum('total_quota'),
            'total_used' => $quotas->sum('used_quota'),
            'total_available' => $quotas->sum('available_quota'),
            'full_quotas' => $quotas->filter->isQuotaFull()->count(),
            'nearly_full_quotas' => $quotas->filter->isQuotaNearlyFull()->count(),
        ];
    }

    /**
     * Format quota info for display
     */
    public function getFormattedQuotaAttribute(): string
    {
        return "{$this->used_quota}/{$this->total_quota}";
    }

    /**
     * Get formatted quota date
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->quota_date->format('d F Y');
    }

    /**
     * Get day name in Indonesian
     */
    public function getDayNameAttribute(): string
    {
        $days = [
            'Sunday' => 'Minggu',
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
        ];

        return $days[$this->quota_date->format('l')] ?? $this->quota_date->format('l');
    }

    /**
     * Boot method untuk auto-update used_quota
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($quota) {
            // Update used quota berdasarkan queue yang sudah ada
            $quota->updateUsedQuota();
        });
    }
}