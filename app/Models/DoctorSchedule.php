<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany; // ✅ TAMBAH INI
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class DoctorSchedule extends Model
{
    protected $fillable = [
        'doctor_name',
        'service_id',
        'days', 
        'start_time',
        'end_time',
        'is_active',
        'foto'
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'is_active' => 'boolean',
        'days' => 'array',
    ];

    /**
     * Relationship ke Service (Poli)
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Service::class);
    }

    /**
     * Relationship ke User (untuk nanti ketika terhubung)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ✅ PERBAIKAN: Relationship ke Queue (antrian yang memilih dokter ini)
     */
    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class, 'doctor_id');
    }

    /**
     * ✅ ACCESSOR: Get photo URL with fallback
     */
    public function getFotoUrlAttribute(): string
    {
        if ($this->foto && Storage::disk('public')->exists($this->foto)) {
            return Storage::url($this->foto);
        }
        
        return asset('assets/img/default-doctor.png');
    }

    /**
     * ✅ ACCESSOR: Check if doctor has photo
     */
    public function getHasFotoAttribute(): bool
    {
        return !empty($this->foto) && Storage::disk('public')->exists($this->foto);
    }

    /**
     * ✅ ACCESSOR: Get formatted days name in Indonesian
     */
    public function getFormattedDaysAttribute(): string
    {
        if (!$this->days || !is_array($this->days)) {
            return '-';
        }

        $dayNames = [
            'monday' => 'Senin',
            'tuesday' => 'Selasa',
            'wednesday' => 'Rabu',
            'thursday' => 'Kamis',
            'friday' => 'Jumat',
            'saturday' => 'Sabtu',
            'sunday' => 'Minggu',
        ];

        $formattedDays = array_map(function($day) use ($dayNames) {
            return $dayNames[$day] ?? ucfirst($day);
        }, $this->days);

        return $this->formatConsecutiveDays($formattedDays);
    }

    /**
     * ✅ HELPER: Format consecutive days
     */
    private function formatConsecutiveDays(array $days): string
    {
        $dayOrder = [
            'Senin' => 1,
            'Selasa' => 2,
            'Rabu' => 3,
            'Kamis' => 4,
            'Jumat' => 5,
            'Sabtu' => 6,
            'Minggu' => 7,
        ];

        usort($days, function($a, $b) use ($dayOrder) {
            return ($dayOrder[$a] ?? 99) - ($dayOrder[$b] ?? 99);
        });

        if (count($days) >= 5 && array_diff(['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'], $days) == []) {
            $weekend = array_intersect(['Sabtu', 'Minggu'], $days);
            if (count($weekend) > 0) {
                return 'Senin-Jumat, ' . implode(', ', $weekend);
            } else {
                return 'Senin-Jumat';
            }
        }

        if (count($days) == 2 && array_diff(['Sabtu', 'Minggu'], $days) == []) {
            return 'Weekend';
        }

        return implode(', ', $days);
    }

    /**
     * Get formatted time range
     */
    public function getTimeRangeAttribute(): string
    {
        return $this->start_time->format('H:i') . ' - ' . $this->end_time->format('H:i');
    }

    /**
     * Get duration in hours
     */
    public function getDurationAttribute(): float
    {
        $start = Carbon::createFromFormat('H:i', $this->start_time->format('H:i'));
        $end = Carbon::createFromFormat('H:i', $this->end_time->format('H:i'));
        
        return $start->diffInHours($end, true);
    }

    /**
     * Check if schedule is active today
     */
    public function isActiveToday(): bool
    {
        $today = strtolower(now()->format('l'));
        return $this->is_active && in_array($today, $this->days ?? []);
    }

    /**
     * ✅ CHECK: Apakah ada konflik dengan schedule existing
     */
    public static function hasConflict(string $doctorName, int $serviceId, array $days, string $startTime, string $endTime, ?int $excludeId = null): bool
    {
        $query = self::where('doctor_name', $doctorName)
            ->where('service_id', $serviceId)
            ->where('is_active', true);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSchedules = $query->get();

        foreach ($existingSchedules as $schedule) {
            $commonDays = array_intersect($days, $schedule->days ?? []);
            
            if (!empty($commonDays)) {
                $existingStart = $schedule->start_time->format('H:i');
                $existingEnd = $schedule->end_time->format('H:i');
                
                if (self::timeOverlaps($startTime, $endTime, $existingStart, $existingEnd)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ✅ HELPER: Check if time ranges overlap
     */
    private static function timeOverlaps(string $start1, string $end1, string $start2, string $end2): bool
    {
        return ($start1 < $end2) && ($end1 > $start2);
    }

    /**
     * Scope untuk jadwal aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * ✅ SCOPE: Filter by specific day
     */
    public function scopeForDay($query, string $day)
    {
        return $query->whereJsonContains('days', strtolower($day));
    }

    /**
     * Scope untuk service tertentu
     */
    public function scopeForService($query, int $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    /**
     * ✅ SCOPE: Filter by doctor name
     */
    public function scopeForDoctor($query, string $doctorName)
    {
        return $query->where('doctor_name', $doctorName);
    }

    /**
     * ✅ SCOPE: Filter yang punya foto
     */
    public function scopeWithPhoto($query)
    {
        return $query->whereNotNull('foto');
    }

    /**
     * Get all unique doctor names
     */
    public static function getUniqueDoctorNames(): array
    {
        return self::distinct('doctor_name')
            ->pluck('doctor_name')
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * ✅ GET: Schedule for specific doctor and day
     */
    public static function getScheduleForDoctorAndDay(string $doctorName, string $day): ?self
    {
        return self::where('doctor_name', $doctorName)
            ->whereJsonContains('days', strtolower($day))
            ->where('is_active', true)
            ->first();
    }

    /**
     * ✅ BOOT: Handle foto deletion saat record dihapus
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($schedule) {
            // Hapus foto dari storage saat record dihapus
            if ($schedule->foto && Storage::disk('public')->exists($schedule->foto)) {
                Storage::disk('public')->delete($schedule->foto);
            }
        });

        static::updating(function ($schedule) {
            // Hapus foto lama jika diganti dengan foto baru
            if ($schedule->isDirty('foto')) {
                $originalFoto = $schedule->getOriginal('foto');
                if ($originalFoto && Storage::disk('public')->exists($originalFoto)) {
                    Storage::disk('public')->delete($originalFoto);
                }
            }
        });
    }
}