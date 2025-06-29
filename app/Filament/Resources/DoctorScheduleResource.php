<?php
// ================================================================================================
// 1. UPDATE: app/Filament/Resources/DoctorScheduleResource.php - LENGKAP
// ================================================================================================

namespace App\Filament\Resources;

use App\Filament\Resources\DoctorScheduleResource\Pages;
use App\Models\DoctorSchedule;
use App\Models\Service;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class DoctorScheduleResource extends Resource
{
    protected static ?string $model = DoctorSchedule::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    
    protected static ?string $navigationGroup = 'Administrasi';
    
    protected static ?string $navigationLabel = 'Jadwal Dokter';
    
    protected static ?string $modelLabel = 'Jadwal Dokter';
    
    protected static ?string $pluralModelLabel = 'Jadwal Dokter';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // ✅ SECTION 1: FOTO DOKTER (BARU)
                Forms\Components\Section::make('Foto Dokter')
                    ->description('Upload foto profil dokter untuk ditampilkan di jadwal')
                    ->schema([
                        Forms\Components\FileUpload::make('foto')
                            ->label('Foto Dokter')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios([
                                '1:1',
                                '4:3',
                                '3:4',
                            ])
                            ->maxSize(2048) // Max 2MB
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/webp'])
                            ->directory('doctor-photos')
                            ->visibility('public')
                            ->imagePreviewHeight('200')
                            ->columnSpanFull()
                            ->helperText('Format: JPG, JPEG, PNG, WebP. Maksimal: 2MB. Rasio yang disarankan: 1:1 (persegi) atau 3:4 (portrait)')
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('1:1')
                            ->panelLayout('compact'),
                    ])
                    ->columns(1)
                    ->collapsible(),

                // ✅ SECTION 2: INFORMASI DOKTER
                Forms\Components\Section::make('Informasi Dokter')
                    ->description('Data dokter dan poli praktik')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('doctor_name')
                                    ->label('Nama Dokter')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('dr. Nama Dokter')
                                    ->helperText('Masukkan nama lengkap dokter dengan gelar'),
                                    
                                Forms\Components\Select::make('service_id')
                                    ->label('Poli')
                                    ->required()
                                    ->relationship('service', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Pilih poli/layanan dari data layanan yang sudah ada')
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Nama Poli/Layanan')
                                            ->required(),
                                        Forms\Components\TextInput::make('prefix')
                                            ->label('Prefix Antrian')
                                            ->required()
                                            ->default('A')
                                            ->maxLength(3),
                                        Forms\Components\TextInput::make('padding')
                                            ->label('Padding Nomor')
                                            ->required()
                                            ->numeric()
                                            ->default(3),
                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Status Aktif')
                                            ->default(true),
                                    ])
                                    ->createOptionUsing(function (array $data) {
                                        $service = Service::create($data);
                                        return $service->id;
                                    }),
                            ]),
                    ]),

                // ✅ SECTION 3: JADWAL PRAKTIK
                Forms\Components\Section::make('Jadwal Praktik')
                    ->description('Atur hari dan jam praktik dokter')
                    ->schema([
                        Forms\Components\CheckboxList::make('days')
                            ->label('Hari Praktik')
                            ->options([
                                'monday' => 'Senin',
                                'tuesday' => 'Selasa',
                                'wednesday' => 'Rabu',
                                'thursday' => 'Kamis',
                                'friday' => 'Jumat',
                                'saturday' => 'Sabtu',
                                'sunday' => 'Minggu',
                            ])
                            ->columns(3)
                            ->required()
                            ->default(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'])
                            ->helperText('Pilih hari-hari praktik dokter'),
                            
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TimePicker::make('start_time')
                                    ->label('Jam Mulai')
                                    ->required()
                                    ->seconds(false)
                                    ->format('H:i')
                                    ->default('08:00'),
                                    
                                Forms\Components\TimePicker::make('end_time')
                                    ->label('Jam Selesai')
                                    ->required()
                                    ->seconds(false)
                                    ->format('H:i')
                                    ->default('16:00')
                                    ->after('start_time'),
                            ]),
                            
                        Forms\Components\Toggle::make('is_active')
                            ->label('Status Aktif')
                            ->default(true)
                            ->helperText('Jadwal hanya berlaku jika status aktif'),
                    ]),

                // Hidden fields
                Forms\Components\Hidden::make('day_of_week'),
                Forms\Components\Hidden::make('user_id'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // ✅ TAMBAH KOLOM FOTO
                Tables\Columns\ImageColumn::make('foto')
                    ->label('Foto')
                    ->circular()
                    ->size(50)
                    ->defaultImageUrl(asset('assets/img/default-doctor.png'))
                    ->extraAttributes(['style' => 'object-fit: cover;']),
                    
                Tables\Columns\TextColumn::make('doctor_name')
                    ->label('Nama Dokter')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->limit(30)
                    ->description(fn (DoctorSchedule $record): string => 
                        $record->service ? "Poli: {$record->service->name}" : ''
                    ),
                    
                Tables\Columns\TextColumn::make('service.name')
                    ->label('Poli')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->limit(20)
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('formatted_days')
                    ->label('Hari Praktik')
                    ->badge()
                    ->separator(',')
                    ->wrap(),
                    
                Tables\Columns\TextColumn::make('time_range')
                    ->label('Jam Praktik')
                    ->badge()
                    ->color('success'),
                    
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('service_id')
                    ->label('Poli')
                    ->relationship('service', 'name')
                    ->searchable()
                    ->preload(),
                    
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua Jadwal')
                    ->trueLabel('Hanya Aktif')
                    ->falseLabel('Hanya Tidak Aktif'),

                // ✅ FILTER FOTO
                Tables\Filters\TernaryFilter::make('has_photo')
                    ->label('Foto')
                    ->placeholder('Semua Dokter')
                    ->trueLabel('Punya Foto')
                    ->falseLabel('Belum Ada Foto')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('foto'),
                        false: fn ($query) => $query->whereNull('foto'),
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->striped()
            ->paginated([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDoctorSchedules::route('/'),
            'create' => Pages\CreateDoctorSchedule::route('/create'),
        ];
    }
    
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('is_active', true)->count() ?: null;
    }
    
    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'success';
    }
}

// ================================================================================================
// 2. UPDATE: app/Http/Controllers/DoctorController.php - SINKRON DENGAN USER
// ================================================================================================

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function jadwaldokter()
    {
        // ✅ AMBIL JADWAL DOKTER DENGAN FOTO
        $doctors = DoctorSchedule::with('service')
            ->where('is_active', true)
            ->get()
            ->groupBy('doctor_name') // Group berdasarkan nama dokter
            ->map(function ($schedules) {
                $firstSchedule = $schedules->first();
                return [
                    'id' => $firstSchedule->id,
                    'doctor_name' => $firstSchedule->doctor_name,
                    'foto' => $firstSchedule->foto, // ✅ INCLUDE FOTO
                    'service' => $firstSchedule->service,
                    'schedules' => $schedules,
                    'all_days' => $schedules->flatMap(function ($schedule) {
                        return $schedule->days ?? [];
                    })->unique()->sort()->values(),
                    'time_range' => $firstSchedule->time_range,
                ];
            })
            ->sortBy('doctor_name');

        return view('jadwaldokter', compact('doctors'));
    }

    public function index()
    {
        $doctors = DoctorSchedule::with('service')
            ->where('is_active', true)
            ->get();
            
        return view('doctors.index', compact('doctors'));
    }

    public function show($id)
    {
        $schedule = DoctorSchedule::with('service')->findOrFail($id);
        return view('doctors.show', compact('schedule'));
    }
}