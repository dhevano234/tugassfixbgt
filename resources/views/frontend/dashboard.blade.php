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
                <!-- Manual Refresh Button -->
                {{-- <button onclick="forceRefreshEstimation()" class="refresh-btn" title="Refresh Estimasi">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button> --}}
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
    
    <!-- Content Row - Status Antrian Only -->
    <div class="content-row">
        <!-- Status Antrian dengan data real -->
        <div class="content-card animate full-width">
            <div class="card-header">
                <i class="fas fa-chart-bar" style="color: #27ae60;"></i>
                <h5>Status Antrian Hari Ini</h5>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
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
    position: relative;
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
    position: relative;
}

.time-value {
    font-size: 2.5rem;
    font-weight: bold;
    color: #3498db;
    transition: all 0.3s ease;
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

.refresh-btn {
    position: absolute;
    top: 15px;
    right: 15px;
    background: #3498db;
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.refresh-btn:hover {
    background: #2980b9;
    transform: rotate(180deg);
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

/* ✅ TAMBAH: Style untuk content card full width */
.content-card.full-width {
    grid-column: 1 / -1;
}

/* ✅ CSS ANIMATIONS */
@keyframes fadeInOut {
    0%, 100% { opacity: 0; transform: scale(0.8); }
    15%, 85% { opacity: 1; transform: scale(1); }
}

@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes slideOutRight {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.updating {
    opacity: 0.7;
    transition: opacity 0.3s ease;
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
    
    .refresh-btn {
        position: static;
        margin-top: 15px;
        width: 100%;
    }
}
</style>

@if($antrianAktif && $antrianAktif->status === 'waiting')
<script>
// ✅ REALTIME UPDATE estimasi waktu tunggu - SINGLE CLEAN VERSION
class EstimationUpdater {
    constructor() {
        this.updateInterval = null;
        this.countdownInterval = null;
        this.lastEstimation = null;
        this.isUpdating = false;
        
        this.init();
    }
    
    init() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.start());
        } else {
            this.start();
        }
    }
    
    start() {
        // Start update interval (every 30 seconds)
        this.updateInterval = setInterval(() => this.updateEstimasi(), 30000);
        
        // First update after 5 seconds
        setTimeout(() => this.updateEstimasi(), 5000);
        
        // Start countdown timer after 1 second
        setTimeout(() => this.startCountdownTimer(), 1000);
        
        // Setup cleanup on page unload
        window.addEventListener('beforeunload', () => this.cleanup());
    }
    
    async updateEstimasi() {
        if (this.isUpdating) return;
        
        const estimasiCard = document.getElementById('estimasiCard');
        if (!estimasiCard) return;
        
        this.isUpdating = true;
        estimasiCard.classList.add('updating');
        
        try {
            const response = await fetch('/dashboard/realtime-estimation', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                if (data.status === 'serving') {
                    this.showNotification('🔔 Antrian Anda sudah dipanggil!', 'success');
                    setTimeout(() => location.reload(), 2000);
                    return;
                }
                
                if (data.data) {
                    this.handleEstimationUpdate(data.data);
                }
            }
        } catch (error) {
            console.error('Error updating estimation:', error);
            this.showNotification('❌ Gagal update estimasi waktu', 'error');
        } finally {
            this.isUpdating = false;
            estimasiCard.classList.remove('updating');
        }
    }
    
    handleEstimationUpdate(data) {
        const estimasiCard = document.getElementById('estimasiCard');
        const newEstimation = data.estimasi_menit;
        
        // Show animation if estimation changed
        if (this.lastEstimation !== null && this.lastEstimation !== newEstimation) {
            this.showEstimationChange(this.lastEstimation, newEstimation);
        }
        
        this.lastEstimation = newEstimation;
        this.updateEstimationUI(data);
        
        // Show status change notifications
        if (data.status === 'delayed' && !estimasiCard.dataset.wasDelayed) {
            this.showNotification('⚠️ Estimasi waktu tunggu bertambah', 'warning');
            estimasiCard.dataset.wasDelayed = 'true';
        } else if (data.status === 'on_time' && estimasiCard.dataset.wasDelayed) {
            this.showNotification('✅ Estimasi waktu kembali normal', 'success');
            estimasiCard.dataset.wasDelayed = 'false';
        }
    }
    
    updateEstimationUI(data) {
        // Update time with animation
        const timeValueElement = document.querySelector('#estimasiTime .time-value');
        if (timeValueElement) {
            timeValueElement.style.transform = 'scale(1.1)';
            timeValueElement.textContent = data.estimasi_menit;
            
            setTimeout(() => {
                timeValueElement.style.transform = 'scale(1)';
            }, 200);
        }
        
        // Update details
        const updates = {
            'posisiAntrian': data.posisi,
            'waktuEstimasi': data.waktu_estimasi,
            'antrianDidepan': data.antrian_didepan
        };
        
        Object.entries(updates).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) element.textContent = value;
        });
        
        // Update status
        const statusElement = document.getElementById('estimasiStatus');
        if (statusElement) {
            statusElement.className = `estimasi-status status-${data.status}`;
            
            if (data.status === 'delayed') {
                const extraDelay = data.extra_delay_minutes ? ` (+${data.extra_delay_minutes} menit)` : '';
                statusElement.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Terlambat dari estimasi${extraDelay}`;
            } else {
                statusElement.innerHTML = '<i class="fas fa-check-circle"></i> Dalam estimasi waktu';
            }
        }
    }
    
    showEstimationChange(oldValue, newValue) {
        const timeContainer = document.getElementById('estimasiTime');
        if (!timeContainer) return;
        
        const changeIndicator = document.createElement('div');
        changeIndicator.className = 'estimation-change';
        changeIndicator.style.cssText = `
            position: absolute;
            top: -10px;
            right: -10px;
            background: ${newValue > oldValue ? '#e74c3c' : '#27ae60'};
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            z-index: 10;
            animation: fadeInOut 3s ease-in-out;
        `;
        
        const difference = Math.abs(newValue - oldValue);
        const sign = newValue > oldValue ? '+' : '-';
        changeIndicator.textContent = `${sign}${difference}m`;
        
        timeContainer.style.position = 'relative';
        timeContainer.appendChild(changeIndicator);
        
        setTimeout(() => {
            if (changeIndicator.parentNode) {
                changeIndicator.parentNode.removeChild(changeIndicator);
            }
        }, 3000);
    }
    
    showNotification(message, type = 'info') {
        // Remove existing notification
        const existingNotification = document.querySelector('.estimation-notification');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        const colors = {
            'success': '#27ae60',
            'warning': '#f39c12',
            'error': '#e74c3c',
            'info': '#3498db'
        };
        
        const notification = document.createElement('div');
        notification.className = `estimation-notification notification-${type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${colors[type] || colors.info};
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            animation: slideInRight 0.3s ease-out;
            max-width: 300px;
        `;
        
        notification.textContent = message;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 5000);
    }
    
    startCountdownTimer() {
        const timeValueElement = document.querySelector('#estimasiTime .time-value');
        if (!timeValueElement) return;
        
        let currentMinutes = parseInt(timeValueElement.textContent);
        
        this.countdownInterval = setInterval(() => {
            if (currentMinutes > 0) {
                currentMinutes--;
                timeValueElement.textContent = currentMinutes;
                
                if (currentMinutes <= 2) {
                    timeValueElement.style.color = '#e74c3c';
                    timeValueElement.style.animation = 'pulse 1s infinite';
                } else if (currentMinutes <= 5) {
                    timeValueElement.style.color = '#f39c12';
                }
            } else {
                clearInterval(this.countdownInterval);
                this.updateEstimasi();
            }
        }, 60000); // Every 1 minute
    }
    
    forceRefresh() {
        this.updateEstimasi();
        this.showNotification('🔄 Estimasi waktu diperbarui', 'info');
    }
    
    cleanup() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
            this.updateInterval = null;
        }
        
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
            this.countdownInterval = null;
        }
    }
}

// Initialize the updater
const estimationUpdater = new EstimationUpdater();

// Global function for manual refresh button
function forceRefreshEstimation() {
    estimationUpdater.forceRefresh();
}
</script>
@endif
@endsection