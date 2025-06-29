<?php
// routes/web.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AntrianController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\RiwayatController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\ProfileController;
use App\Services\SessionManager;

// Halaman Utama
Route::get('/', fn () => view('welcome'))->name('welcome');

// Auth: Login & Register
Route::controller(LoginController::class)->group(function () {
    Route::get('/login', 'showLoginForm')->name('login');
    Route::post('/login', 'login');
    Route::post('/logout', 'logout')->name('logout');
});

Route::controller(RegisterController::class)->group(function () {
    Route::get('/register', 'showRegisterForm')->name('register');
    Route::post('/register', 'register');
});

// Password Reset Routes
Route::middleware('guest:web')->group(function () {
    Route::get('forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');
    Route::get('reset-password/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'reset'])->name('password.update');
});

// Routes untuk USER/PASIEN SAJA
Route::middleware(['auth:web', 'role.user'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/home', [HomeController::class, 'index'])->name('home');

    // Profile Routes
    Route::get('/editprofile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password.update');

    // ✅ PERBAIKAN: Riwayat Pasien - Routes yang lengkap
    Route::prefix('riwayatkunjungan')->name('riwayat.')->controller(RiwayatController::class)->group(function () {
        Route::get('/', 'index')->name('index');                           // GET /riwayatkunjungan
        Route::get('/export', 'export')->name('export');                   // GET /riwayatkunjungan/export
        Route::get('/statistics', 'statistics')->name('statistics');       // GET /riwayatkunjungan/statistics
        Route::get('/{queue}', 'show')->name('show');                      // GET /riwayatkunjungan/{id}
    });

    // ✅ EXISTING: Antrian Routes dengan struktur yang konsisten
    Route::prefix('antrian')->name('antrian.')->controller(AntrianController::class)->group(function () {
        Route::get('/', 'index')->name('index');                           // GET /antrian
        Route::get('/create', 'create')->name('create');                   // GET /antrian/create
        Route::post('/', 'store')->name('store');                          // POST /antrian
        Route::get('/{queue}', 'show')->name('show');                      // GET /antrian/{id}
        Route::get('/{queue}/edit', 'edit')->name('edit');                 // GET /antrian/{id}/edit
        Route::put('/{queue}', 'update')->name('update');                  // PUT /antrian/{id}
        Route::delete('/{queue}', 'destroy')->name('destroy');             // DELETE /antrian/{id}
        Route::get('/{queue}/print', 'print')->name('print');              // GET /antrian/{id}/print
    });

    // Doctor Info
    Route::get('/doctors', [DoctorController::class, 'index'])->name('doctors.index');
    Route::get('/doctors/{schedule}', [DoctorController::class, 'show'])->name('doctors.show');
    Route::get('/jadwaldokter', [DoctorController::class, 'jadwaldokter'])->name('jadwaldokter');
});

// ✅ Test Route untuk Multi-Session (Development only)
Route::get('/test-sessions', function () {
    return response()->json([
        'active_sessions' => SessionManager::getActiveSessions(),
        'cookies' => request()->cookies->all(),
        'session_id' => session()->getId(),
    ]);
});