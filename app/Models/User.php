<?php
// File: app/Models/User.php - PERBAIKAN LENGKAP

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'gender',
        'birth_date',
        'address',
        'nomor_ktp',
        'medical_record_number', // ✅ TAMBAH INI
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
        ];
    }

    /**
     * Boot method untuk auto-generate medical record number
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            // Set default role jika tidak diset
            if (empty($user->role)) {
                $user->role = 'user';
            }
            
            // Generate medical record number untuk user dengan role 'user'
            if ($user->role === 'user' && !$user->medical_record_number) {
                $user->medical_record_number = self::generateMedicalRecordNumber();
            }
        });
    }

    /**
     * ✅ FIX: Generate unique medical record number
     * Format: RM-YYYYMMDD-XXXX
     */
    public static function generateMedicalRecordNumber(): string
    {
        $today = Carbon::now()->format('Ymd');
        $prefix = "RM-{$today}-";
        
        // Cari nomor terakhir hari ini
        $lastRecord = self::where('medical_record_number', 'LIKE', $prefix . '%')
            ->orderBy('medical_record_number', 'desc')
            ->first();
        
        if ($lastRecord) {
            // Ambil 4 digit terakhir dan increment
            $lastNumber = (int) substr($lastRecord->medical_record_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            // Nomor pertama hari ini
            $newNumber = 1;
        }
        
        // Format dengan leading zeros (4 digit)
        $formattedNumber = str_pad($newNumber, 4, '0', STR_PAD_LEFT);
        
        return $prefix . $formattedNumber;
    }

    /**
     * ✅ FIX: Assign medical record number to existing user
     */
    public function assignMedicalRecordNumber(): void
    {
        if (!$this->medical_record_number && $this->role === 'user') {
            $this->medical_record_number = self::generateMedicalRecordNumber();
            $this->save();
        }
    }

    /**
     * Relationship ke Queue (antrian yang dibuat user)
     */
    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class);
    }

    /**
     * Relationship ke MedicalRecord (sebagai pasien)
     */
    public function medicalRecordsAsPatient(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'user_id');
    }

    /**
     * Relationship ke MedicalRecord (sebagai dokter)
     */
    public function medicalRecordsAsDoctor(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'doctor_id');
    }

    /**
     * Alias untuk backward compatibility
     */
    public function medicalRecords(): HasMany
    {
        return $this->medicalRecordsAsPatient();
    }

    /**
     * Cek apakah data profil sudah lengkap untuk buat antrian
     */
    public function isProfileCompleteForQueue(): bool
    {
        return !empty($this->phone) && 
               !empty($this->gender) && 
               !empty($this->birth_date) && 
               !empty($this->address);
    }

    /**
     * Get missing profile data untuk buat antrian
     */
    public function getMissingProfileData(): array
    {
        $missing = [];
        
        if (empty($this->phone)) $missing[] = 'Nomor HP';
        if (empty($this->gender)) $missing[] = 'Jenis Kelamin';
        if (empty($this->birth_date)) $missing[] = 'Tanggal Lahir';
        if (empty($this->address)) $missing[] = 'Alamat';
        
        return $missing;
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is dokter
     */
    public function isDokter(): bool
    {
        return $this->role === 'dokter';
    }

    /**
     * Check if user is pasien/user
     */
    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Get user's age
     */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_date ? $this->birth_date->diffInYears(now()) : null;
    }

    /**
     * Get formatted gender
     */
    public function getGenderLabelAttribute(): string
    {
        return match($this->gender) {
            'Laki-laki' => 'Laki-laki',
            'Perempuan' => 'Perempuan',
            'male' => 'Laki-laki',
            'female' => 'Perempuan',
            default => $this->gender ?? 'Tidak diketahui'
        };
    }

    /**
     * Get formatted medical record number untuk display
     */
    public function getFormattedMrnAttribute(): string
    {
        return $this->medical_record_number ?? 'Belum ada';
    }
}