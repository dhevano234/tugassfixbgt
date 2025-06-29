{{-- File: resources/views/filament/dokter/pages/dashboard.blade.php --}}
{{-- SIMPLE CLEAN Dashboard - Filament Style untuk Dokter dengan Jadwal --}}

<x-filament-panels::page>
<div class="space-y-6">
    {{-- Welcome Section - Simple seperti Admin --}}
    <div class="bg-white rounded-lg shadow border p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4">
                {{-- Avatar Simple --}}
                <div class="w-16 h-16 bg-gray-900 rounded-full flex items-center justify-center">
                    <span class="text-xl font-semibold text-white">
                        {{ strtoupper(substr($user->name, 0, 2)) }}
                    </span>
                </div>

                {{-- Welcome Text --}}
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">
                        Selamat Datang
                    </h1>
                    <p class="text-lg text-gray-600">
                        {{ $user->name }}
                    </p>
                    <p class="text-sm text-gray-500">
                        Panel Dokter - {{ now()->format('l, d F Y') }}
                    </p>
                </div>
            </div>

            {{-- Logout Button Simple --}}
            <div>
                <form action="{{ route('filament.dokter.auth.logout') }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Jadwal Dokter Hari Ini --}}
    @php
        $today = strtolower(now()->format('l')); // monday, tuesday, etc.
        $todaySchedules = \App\Models\DoctorSchedule::where('is_active', true)
            ->where('day_of_week', $today)
            ->with('service')
            ->get();
        $mySchedule = $todaySchedules->where('doctor_name', $user->name)->first();
    @endphp

    <div class="bg-white rounded-lg shadow border p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4m4 0V9a2 2 0 00-2-2H6a2 2 0 00-2 2v8a2 2 0 002 2h8m-8-4h8m-8 4h8"/>
            </svg>
            Jadwal Praktik Hari Ini ({{ ucfirst(\Carbon\Carbon::now()->locale('id')->translatedFormat('l, d F Y')) }})
        </h3>

        @if($mySchedule)
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <div class="w-3 h-3 bg-green-400 rounded-full mr-3"></div>
                    <div>
                        <span class="font-medium text-green-800">Anda memiliki jadwal praktik hari ini</span>
                        <div class="text-sm text-green-600 mt-1">
                            <strong>{{ $mySchedule->service->name }}</strong> • 
                            {{ $mySchedule->time_range }} • 
                            <span class="bg-green-100 px-2 py-1 rounded text-xs font-medium">{{ $mySchedule->day_name }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <div class="w-3 h-3 bg-gray-400 rounded-full mr-3"></div>
                    <div class="text-sm text-gray-600">
                        Tidak ada jadwal praktik untuk hari ini
                    </div>
                </div>
            </div>
        @endif

        {{-- Daftar Semua Dokter Hari Ini --}}
        @if($todaySchedules->count() > 0)
            <h4 class="text-md font-medium text-gray-700 mb-3">Dokter Praktik Hari Ini:</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($todaySchedules as $schedule)
                    <div class="bg-gray-50 rounded-lg p-3 border {{ $schedule->doctor_name === $user->name ? 'border-green-300 bg-green-50' : 'border-gray-200' }}">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                <span class="text-sm font-medium text-blue-600">
                                    {{ strtoupper(substr($schedule->doctor_name, 0, 2)) }}
                                </span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">
                                    {{ $schedule->doctor_name }}
                                    @if($schedule->doctor_name === $user->name)
                                        <span class="text-green-600">(Anda)</span>
                                    @endif
                                </p>
                                <div class="flex items-center space-x-2 text-xs text-gray-500">
                                    <span>{{ $schedule->service->name }}</span>
                                    <span>•</span>
                                    <span>{{ $schedule->time_range }}</span>
                                </div>
                                <div class="mt-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ 
                                        $schedule->day_of_week === 'monday' ? 'bg-blue-100 text-blue-800' :
                                        ($schedule->day_of_week === 'tuesday' ? 'bg-green-100 text-green-800' :
                                        ($schedule->day_of_week === 'wednesday' ? 'bg-yellow-100 text-yellow-800' :
                                        ($schedule->day_of_week === 'thursday' ? 'bg-orange-100 text-orange-800' :
                                        ($schedule->day_of_week === 'friday' ? 'bg-red-100 text-red-800' :
                                        ($schedule->day_of_week === 'saturday' ? 'bg-purple-100 text-purple-800' :
                                        'bg-gray-100 text-gray-800')))))
                                    }}">
                                        {{ $schedule->day_name }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-500">Tidak ada dokter yang terjadwal praktik hari ini.</p>
        @endif
    </div>

    {{-- Connection Status - Simple Info --}}
    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
        <div class="flex items-center">
            <div class="w-2 h-2 bg-green-400 rounded-full mr-3"></div>
            <div class="text-sm">
                <span class="font-medium text-green-800">Panel Dokter</span>
                <span class="text-green-600 ml-2">• Terhubung dengan Panel Admin</span>
            </div>
        </div>
    </div>

    {{-- Main Navigation Cards - Simple Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        {{-- Kelola Antrian --}}
        <a href="{{ route('filament.dokter.resources.queues.index') }}" 
           class="block bg-white rounded-lg shadow border hover:shadow-md transition-shadow">
            <div class="p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-100 rounded-md flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Kelola Antrian</h3>
                        <p class="text-sm text-gray-500">Lihat dan kelola antrian pasien</p>
                    </div>
                </div>
            </div>
        </a>

        {{-- Rekam Medis --}}
        <a href="{{ route('filament.dokter.resources.medical-records.index') }}" 
           class="block bg-white rounded-lg shadow border hover:shadow-md transition-shadow">
            <div class="p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-100 rounded-md flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Rekam Medis</h3>
                        <p class="text-sm text-gray-500">Kelola rekam medis pasien</p>
                    </div>
                </div>
            </div>
        </a>

        {{-- Data Pasien --}}
        <a href="{{ route('filament.dokter.resources.patients.index') }}" 
           class="block bg-white rounded-lg shadow border hover:shadow-md transition-shadow">
            <div class="p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-purple-100 rounded-md flex items-center justify-center">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Data Pasien</h3>
                        <p class="text-sm text-gray-500">Lihat data pasien terdaftar</p>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Test Audio Button (hanya untuk development) --}}
    @if(app()->environment('local'))
    <div class="bg-gray-50 rounded-lg border p-4">
        <div class="flex items-center justify-between">
            <div class="text-sm text-gray-600">
                <span class="font-medium">Debug Audio:</span> Test sistem audio
            </div>
            <button onclick="testSharedAudio('Test audio sistem klinik')" 
                    class="inline-flex items-center px-3 py-1 text-xs font-medium rounded border border-gray-300 text-gray-700 bg-white hover:bg-gray-50">
                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217z" clip-rule="evenodd"/>
                </svg>
                Test
            </button>
        </div>
    </div>
    @endif

    {{-- Simple Audio System --}}
    <script>
        // Simple Audio System - Clean & Minimal
        console.log('🎵 Simple Audio System Loading...');

        window.testSharedAudio = function(message) {
            playAudioMessage(message || 'Test audio berhasil');
        };

        window.handleQueueCall = function(message) {
            playAudioMessage(message);
        };

        window.playQueueAudio = function(message) {
            playAudioMessage(message);
        };

        function playAudioMessage(message) {
            if (!message) return;
            
            try {
                if ('speechSynthesis' in window) {
                    speechSynthesis.cancel();
                    
                    const utterance = new SpeechSynthesisUtterance(message);
                    utterance.lang = 'id-ID';
                    utterance.rate = 0.9;
                    utterance.volume = 1.0;
                    
                    const voices = speechSynthesis.getVoices();
                    const indonesianVoice = voices.find(voice => 
                        voice.lang.includes('id') || voice.name.toLowerCase().includes('indonesia')
                    );
                    if (indonesianVoice) {
                        utterance.voice = indonesianVoice;
                    }
                    
                    speechSynthesis.speak(utterance);
                    console.log('🔊 Audio played:', message);
                } else {
                    console.warn('Speech synthesis not supported');
                }
            } catch (error) {
                console.error('Audio error:', error);
            }
        }

        // Load voices
        if ('speechSynthesis' in window) {
            speechSynthesis.getVoices();
        }

        // Livewire events
        document.addEventListener('livewire:initialized', function() {
            if (window.Livewire && window.Livewire.on) {
                window.Livewire.on('queue-called', function(message) {
                    playAudioMessage(message);
                });
                console.log('✅ Livewire audio events ready');
            }
        });

        console.log('✅ Simple Audio System Ready');
    </script>
</div>
</x-filament-panels::page>