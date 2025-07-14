<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WeeklyQuotaResource\Pages;
use App\Models\WeeklyQuota;
use App\Models\DoctorSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WeeklyQuotaResource extends Resource
{
    protected static ?string $model = WeeklyQuota::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationLabel = 'Kuota Antrian';
    protected static ?string $modelLabel = 'Kuota Antrian';
    protected static ?string $pluralModelLabel = 'Kuota Antrian';
    protected static ?string $navigationGroup = 'Administrasi';
    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Pengaturan Kuota Antrian')
                    ->description('Atur kuota per hari dalam seminggu untuk dokter')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('doctor_schedule_id')
                                    ->label('Dokter')
                                    ->relationship('doctorSchedule', 'doctor_name')
                                    ->getOptionLabelFromRecordUsing(fn (DoctorSchedule $record): string => 
                                        $record->doctor_name . ' - ' . ($record->service->name ?? 'Unknown') . 
                                        ' (' . $record->start_time->format('H:i') . ' - ' . $record->end_time->format('H:i') . ')'
                                    )
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $dayOfWeek = $get('day_of_week');
                                        if ($state && $dayOfWeek) {
                                            $existingQuota = WeeklyQuota::where('doctor_schedule_id', $state)
                                                ->where('day_of_week', $dayOfWeek)
                                                ->first();
                                            
                                            if ($existingQuota) {
                                                $set('existing_quota_warning', 
                                                    "Kuota untuk dokter ini pada hari {$existingQuota->day_name_indonesia} sudah ada: {$existingQuota->total_quota}"
                                                );
                                            } else {
                                                $set('existing_quota_warning', null);
                                            }
                                        }
                                    })
                                    ->helperText('Pilih dokter yang akan diberi kuota'),

                                Forms\Components\Select::make('day_of_week')
                                    ->label('Hari')
                                    ->options([
                                        'monday' => 'Senin',
                                        'tuesday' => 'Selasa',
                                        'wednesday' => 'Rabu',
                                        'thursday' => 'Kamis',
                                        'friday' => 'Jumat',
                                        'saturday' => 'Sabtu',
                                        'sunday' => 'Minggu',
                                    ])
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $doctorId = $get('doctor_schedule_id');
                                        if ($state && $doctorId) {
                                            $existingQuota = WeeklyQuota::where('doctor_schedule_id', $doctorId)
                                                ->where('day_of_week', $state)
                                                ->first();
                                            
                                            if ($existingQuota) {
                                                $dayNames = [
                                                    'monday' => 'Senin',
                                                    'tuesday' => 'Selasa',
                                                    'wednesday' => 'Rabu',
                                                    'thursday' => 'Kamis',
                                                    'friday' => 'Jumat',
                                                    'saturday' => 'Sabtu',
                                                    'sunday' => 'Minggu',
                                                ];
                                                
                                                $set('existing_quota_warning', 
                                                    "Kuota untuk dokter ini pada hari {$dayNames[$state]} sudah ada: {$existingQuota->total_quota}"
                                                );
                                            } else {
                                                $set('existing_quota_warning', null);
                                            }
                                        }
                                    })
                                    ->helperText('Pilih hari dalam seminggu'),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('total_quota')
                                    ->label('Jumlah Kuota')
                                    ->numeric()
                                    ->required()
                                    ->default(20)
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->step(1)
                                    ->helperText('Kuota ini akan digunakan setiap minggu pada hari yang sama'),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Status Aktif')
                                    ->default(true)
                                    ->helperText('Kuota hanya berlaku jika dalam status aktif'),
                            ]),

                        Forms\Components\Placeholder::make('existing_quota_warning')
                            ->label('')
                            ->content(fn (callable $get) => $get('existing_quota_warning'))
                            ->visible(fn (callable $get) => !empty($get('existing_quota_warning')))
                            ->extraAttributes(['class' => 'text-danger-600 font-medium']),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->placeholder('Tambahkan catatan jika diperlukan...')
                            ->rows(3)
                            ->helperText('Catatan optional untuk kuota ini'),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('doctorSchedule.doctor_name')
                    ->label('Dokter')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('doctorSchedule.service.name')
                    ->label('Layanan')
                    ->searchable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('day_name_indonesia')
                    ->label('Hari')
                    ->badge()
                    ->color(fn (WeeklyQuota $record): string => 
                        $record->day_of_week === strtolower(today()->format('l')) ? 'success' : 'gray'
                    )
                    ->sortable(['day_of_week']),

                Tables\Columns\TextColumn::make('doctorSchedule.time_range')
                    ->label('Jam Praktek')
                    ->getStateUsing(function (WeeklyQuota $record) {
                        $schedule = $record->doctorSchedule;
                        return $schedule ? $schedule->start_time->format('H:i') . ' - ' . $schedule->end_time->format('H:i') : '-';
                    })
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('total_quota')
                    ->label('Kuota Total')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('formatted_quota_today')
                    ->label('Hari Ini')
                    ->badge()
                    ->color(fn (WeeklyQuota $record): string => $record->getStatusColor())
                    ->visible(fn () => true)
                    ->tooltip(fn (WeeklyQuota $record) => 
                        $record->day_of_week === strtolower(today()->format('l')) 
                            ? "Penggunaan hari ini: {$record->usage_percentage_today}%"
                            : "Bukan hari praktik hari ini"
                    ),

                Tables\Columns\TextColumn::make('usage_percentage_today')
                    ->label('Penggunaan')
                    ->formatStateUsing(function (WeeklyQuota $record): string {
                        if ($record->day_of_week !== strtolower(today()->format('l'))) {
                            return '-';
                        }
                        return number_format($record->usage_percentage_today, 1) . '%';
                    })
                    ->color(fn (WeeklyQuota $record): string => 
                        $record->day_of_week === strtolower(today()->format('l')) 
                            ? $record->getStatusColor() 
                            : 'gray'
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('status_label_today')
                    ->label('Status')
                    ->badge()
                    ->color(fn (WeeklyQuota $record): string => 
                        $record->day_of_week === strtolower(today()->format('l')) 
                            ? $record->getStatusColor() 
                            : 'gray'
                    )
                    ->formatStateUsing(function (WeeklyQuota $record): string {
                        if ($record->day_of_week !== strtolower(today()->format('l'))) {
                            return 'Tidak Aktif';
                        }
                        return $record->status_label_today;
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable()
                    ->toggledHiddenByDefault(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('doctor_schedule_id')
                    ->label('Dokter')
                    ->relationship('doctorSchedule', 'doctor_name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('day_of_week')
                    ->label('Hari')
                    ->options([
                        'monday' => 'Senin',
                        'tuesday' => 'Selasa',
                        'wednesday' => 'Rabu',
                        'thursday' => 'Kamis',
                        'friday' => 'Jumat',
                        'saturday' => 'Sabtu',
                        'sunday' => 'Minggu',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status Hari Ini')
                    ->options([
                        'available' => 'Tersedia',
                        'near_full' => 'Hampir Penuh',
                        'full' => 'Penuh',
                        'empty' => 'Kosong',
                        'inactive' => 'Tidak Aktif Hari Ini',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        $todayDayOfWeek = strtolower(today()->format('l'));
                        
                        return match($value) {
                            'available' => $query->where('day_of_week', $todayDayOfWeek),
                            'inactive' => $query->where('day_of_week', '!=', $todayDayOfWeek),
                            default => $query,
                        };
                    }),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif')
                    ->trueLabel('Aktif')
                    ->falseLabel('Tidak Aktif')
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    
                    Tables\Actions\BulkAction::make('update_quota_amount')
                        ->label('Update Jumlah Kuota')
                        ->icon('heroicon-o-pencil')
                        ->color('warning')
                        ->form([
                            Forms\Components\TextInput::make('new_quota_amount')
                                ->label('Jumlah Kuota Baru')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(100)
                                ->helperText('Masukkan jumlah kuota baru untuk semua item yang dipilih'),
                        ])
                        ->action(function ($records, array $data) {
                            $newAmount = $data['new_quota_amount'];
                            $count = 0;
                            
                            foreach ($records as $record) {
                                $record->update(['total_quota' => $newAmount]);
                                $count++;
                            }
                            
                            \Filament\Notifications\Notification::make()
                                ->title('Kuota Berhasil Diperbarui')
                                ->body("Berhasil update {$count} kuota menjadi {$newAmount}")
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Update Jumlah Kuota')
                        ->modalSubmitActionLabel('Ya, Update'),
                ]),
            ])
            ->defaultSort('doctor_schedule_id')
            ->poll('30s');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWeeklyQuotas::route('/'),
            'create' => Pages\CreateWeeklyQuota::route('/create'),
            'view' => Pages\ViewWeeklyQuota::route('/{record}'),
            'edit' => Pages\EditWeeklyQuota::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $todayDayOfWeek = strtolower(today()->format('l'));
        return static::getModel()::where('day_of_week', $todayDayOfWeek)
            ->where('is_active', true)
            ->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $todayDayOfWeek = strtolower(today()->format('l'));
        $todayQuotas = static::getModel()::where('day_of_week', $todayDayOfWeek)
            ->where('is_active', true)
            ->get();
        
        $fullQuotas = $todayQuotas->filter(fn ($quota) => $quota->isQuotaFullToday())->count();
        
        if ($fullQuotas > 0) {
            return 'warning';
        }
        
        return $todayQuotas->count() > 0 ? 'success' : 'gray';
    }
}