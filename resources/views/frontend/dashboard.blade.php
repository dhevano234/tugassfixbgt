@extends('layouts.main')

@section('title', 'Dashboard')

@section('content')
<main class="main-content">
    <!-- Welcome Card -->
    <div class="welcome-card animate">
        <h1>Selamat Datang, {{ Auth::user()->name }}! 👋</h1>
        <p>Kelola antrian dan layanan klinik dengan mudah</p>
    </div>
    
    <!-- ✅ ANTRIAN AKTIF CARD dengan estimasi realtime -->
    @if($antrianAktif)
    <div class="antrian-aktif-card animate" id="antrianAktifCard">
        <div class="card-header">
            <h5><i class="fas fa-clock" style="color: #f39c12;"></i> Antrian Aktif Anda</h5>
            <div class="status-badge status-{{ strtolower($antrianAktif->status) }}">
                {{ ucfirst($antrianAktif->status) }}
            </div>
        </div>
        
        <div class="antrian-content">
            <div class="antrian-info">
                <div class="queue-number">{{ $antrianAktif->number }}</div>
                <div class="queue-details">
                    <p><strong>Layanan:</strong> {{ $antrianAktif->service->name ?? 'Unknown' }}</p>
                    <p><strong>Tanggal:</strong> {{ $antrianAktif->created_at->format('d F Y') }}</p>
                    <p><strong>Jam Ambil:</strong> {{ $antrianAktif->created_at->format('H:i') }} WIB</p>
                </div>
            </div>
            
            @if($antrianAktif->status === 'waiting' && $estimasiInfo)
            <div class="estimasi-card" id="estimasiCard">
                <h6><i class="fas fa-hourglass-half"></i> Estimasi Waktu Tunggu</h6>
                <div class="estimasi-content">
                    <div class="estimasi-time" id="estimasiTime">
                        <span class="time-value">{{ $estimasiInfo['estimasi_menit'] }}</span>
                        <span class="time-unit">menit</span>
                    </div>
                    <div class="estimasi-details">
                        <p>📍 Posisi dalam antrian: <strong id="posisiAntrian">{{ $estimasiInfo['posisi'] }}</strong></p>
                        <p>⏰ Estimasi dipanggil: <strong id="waktuEstimasi">{{ $estimasiInfo['waktu_estimasi'] }}</strong> WIB</p>
                        <p>👥 Antrian di depan: <strong id="antrianDidepan">{{ $estimasiInfo['antrian_didepan'] }}</strong> orang</p>
                    </div>
                    <div class="estimasi-status status-{{ $estimasiInfo['status'] }}" id="estimasiStatus">
                        @if($estimasiInfo['status'] === 'delayed')
                            <i class="fas fa-exclamation-triangle"></i> Terlambat dari estimasi
                        @else
                            <i class="fas fa-check-circle"></i> Dalam estimasi waktu
                        @endif
                    </div>
                </div>
            </div>
            @elseif($antrianAktif->status === 'serving')
            <div class="serving-card">
                <h6><i class="fas fa-user-md" style="color: #27ae60;"></i> Sedang Dilayani</h6>
                <p>Silakan menuju ke loket yang telah ditentukan</p>
            </div>
            @endif
        </div>
    </div>
    @else
    <div class="no-antrian-card animate">
        <div class="card-content">
            <i class="fas fa-plus-circle" style="font-size: 3rem; color: #3498db; margin-bottom: 15px;"></i>
            <h5>Belum Ada Antrian Aktif</h5>
            <p>Buat antrian baru untuk mulai mendapatkan layanan</p>
            <a href="/antrian/create" class="btn btn-primary">
                <i class="fas fa-plus"></i> Buat Antrian
            </a>
        </div>
    </div>
    @endif
    
    <!-- Stats Row dengan data real -->
    <div class="stats-row">
        <div class="stat-card blue animate">
            <div class="stat-icon blue">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-number">{{ $stats['antrian_hari_ini'] }}</div>
            <div class="stat-label">Antrian Hari Ini</div>
        </div>
        
        <div class="stat-card green animate">
            <div class="stat-icon green">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-number">{{ $stats['total_pasien'] }}</div>
            <div class="stat-label">Total Pasien</div>
        </div>
        
        <div class="stat-card orange animate">
            <div class="stat-icon orange">
                <i class="fas fa-user-md"></i>
            </div>
            <div class="stat-number">{{ $stats['dokter_aktif'] }}</div>
            <div class="stat-label">Dokter Aktif</div>
        </div>
    </div>
    
    <!-- Content Row -->
    <div class="content-row">
        <!-- Status Antrian dengan data real -->
        <div class="content-card animate">
            <div class="card-header">
                <i class="fas fa-chart-bar" style="color: #27ae60;"></i>
                <h5>Status Antrian Hari Ini</h5>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div style="text-align: center; padding: 15px; background: #e3f2fd; border-radius: 10px;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #1976d2;">{{ $statusAntrian['menunggu'] }}</div>
                    <small style="color: #7f8c8d;">Menunggu</small>
                </div>
                <div style="text-align: center; padding: 15px; background: #fff3e0; border-radius: 10px;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #f57c00;">{{ $statusAntrian['dipanggil'] }}</div>
                    <small style="color: #7f8c8d;">Dipanggil</small>
                </div>
                <div style="text-align: center; padding: 15px; background: #e8f5e8; border-radius: 10px;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #2e7d32;">{{ $statusAntrian['selesai'] }}</div>
                    <small style="color: #7f8c8d;">Selesai</small>
                </div>
                <div style="text-align: center; padding: 15px; background: #ffebee; border-radius: 10px;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: #d32f2f;">{{ $statusAntrian['dibatalkan'] }}</div>
                    <small style="color: #7f8c8d;">Dibatalkan</small>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="content-card animate">
            <div class="card-header">
                <i class="fas fa-bolt" style="color: #3498db;"></i>
                <h5>Quick Actions</h5>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <a href="/antrian/create" class="btn btn-primary" style="justify-content: flex-start;">
                    <i class="fas fa-plus-circle"></i>
                    Buat Antrian Baru
                </a>
                <a href="/riwayatkunjungan" class="btn btn-secondary" style="justify-content: flex-start;">
                    <i class="fas fa-history"></i>
                    Lihat Riwayat
                </a>
                <a href="/jadwaldokter" class="btn btn-secondary" style="justify-content: flex-start;">
                    <i class="fas fa-calendar"></i>
                    Jadwal Dokter
                </a>
            </div>
        </div>
    </div>
</main>

<style>
/* ✅ STYLES untuk antrian aktif card */
.antrian-aktif-card, .no-antrian-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    border-left: 5px solid #3498db;
}

.antrian-aktif-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #ecf0f1;
}

.antrian-aktif-card .card-header h5 {
    margin: 0;
    font-weight: 600;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 10px;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-waiting {
    background: #fef3cd;
    color: #856404;
}

.status-serving {
    background: #d4edda;
    color: #155724;
}

.antrian-content {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
    align-items: start;
}

.antrian-info {
    display: flex;
    align-items: center;
    gap: 20px;
}

.queue-number {
    font-size: 3rem;
    font-weight: bold;
    color: #3498db;
    background: #ecf0f1;
    padding: 20px;
    border-radius: 15px;
    text-align: center;
    min-width: 100px;
}

.queue-details p {
    margin: 8px 0;
    color: #7f8c8d;
}

.queue-details strong {
    color: #2c3e50;
}

/* ✅ STYLES untuk estimasi card */
.estimasi-card, .serving-card {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    border-left: 4px solid #3498db;
}

.estimasi-card h6, .serving-card h6 {
    margin: 0 0 15px 0;
    color: #2c3e50;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.estimasi-time {
    text-align: center;
    margin-bottom: 15px;
}

.time-value {
    font-size: 2.5rem;
    font-weight: bold;
    color: #3498db;
}

.time-unit {
    font-size: 1.2rem;
    color: #7f8c8d;
    margin-left: 5px;
}

.estimasi-details p {
    margin: 8px 0;
    font-size: 14px;
    color: #7f8c8d;
}

.estimasi-details strong {
    color: #2c3e50;
}

.estimasi-status {
    margin-top: 15px;
    padding: 10px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    text-align: center;
}

.estimasi-status.status-on_time {
    background: #d4edda;
    color: #155724;
}

.estimasi-status.status-delayed {
    background: #f8d7da;
    color: #721c24;
}

.no-antrian-card .card-content {
    text-align: center;
    padding: 30px;
}

.no-antrian-card h5 {
    color: #2c3e50;
    margin: 15px 0 10px 0;
}

.no-antrian-card p {
    color: #7f8c8d;
    margin-bottom: 20px;
}

/* ✅ RESPONSIVE */
@media (max-width: 768px) {
    .antrian-content {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .antrian-info {
        flex-direction: column;
        text-align: center;
    }
    
    .queue-number {
        font-size: 2.5rem;
        padding: 15px;
        min-width: 80px;
    }
}

/* Update status untuk real-time */
.updating {
    opacity: 0.7;
    transition: opacity 0.3s ease;
}
</style>

@if($antrianAktif && $antrianAktif->status === 'waiting')
<script>
// ✅ REALTIME UPDATE estimasi waktu tunggu
let updateInterval;

function updateEstimasi() {
    const estimasiCard = document.getElementById('estimasiCard');
    if (!estimasiCard) return;
    
    estimasiCard.classList.add('updating');
    
    fetch('/dashboard/realtime-estimation', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.status === 'serving') {
                // Redirect atau reload jika sudah dipanggil
                location.reload();
                return;
            }
            
            // Update UI dengan data terbaru
            if (data.data) {
                document.getElementById('estimasiTime').innerHTML = 
                    `<span class="time-value">${data.data.estimasi_menit}</span><span class="time-unit">menit</span>`;
                
                document.getElementById('posisiAntrian').textContent = data.data.posisi;
                document.getElementById('waktuEstimasi').textContent = data.data.waktu_estimasi;
                document.getElementById('antrianDidepan').textContent = data.data.antrian_didepan;
                
                const statusElement = document.getElementById('estimasiStatus');
                statusElement.className = `estimasi-status status-${data.data.status}`;
                
                if (data.data.status === 'delayed') {
                    statusElement.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Terlambat dari estimasi';
                } else {
                    statusElement.innerHTML = '<i class="fas fa-check-circle"></i> Dalam estimasi waktu';
                }
            }
        }
    })
    .catch(error => {
        console.error('Error updating estimation:', error);
    })
    .finally(() => {
        estimasiCard.classList.remove('updating');
    });
}

// Update setiap 30 detik
document.addEventListener('DOMContentLoaded', function() {
    updateInterval = setInterval(updateEstimasi, 30000);
    
    // Update pertama setelah 5 detik
    setTimeout(updateEstimasi, 5000);
});

// Clear interval saat halaman di-unload
window.addEventListener('beforeunload', function() {
    if (updateInterval) {
        clearInterval(updateInterval);
    }
});
</script>
@endif
@endsection