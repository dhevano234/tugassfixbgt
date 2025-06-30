<?php
// File: app/Filament/Resources/PatientManagementResource.php
// FIXED VERSION - Method table() dipecah untuk menghindari PHP6613 warning

namespace App\Filament\Resources;

use App\Filament\Resources\PatientManagementResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class PatientManagementResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Data Pasien';
    protected static ?string $modelLabel = 'Pasien';
    protected static ?string $pluralModelLabel = 'Data Pasien';
    protected static ?string $navigationGroup = 'Administrasi';
    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', 'user');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::getFormSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::getTableColumns())
            ->defaultSort('created_at', 'desc')
            ->filters(self::getTableFilters())
            ->actions(self::getTableActions())
            ->bulkActions(self::getBulkActions());
    }

    // ========================================
    // HELPER METHODS - Memecah kompleksitas
    // ========================================

    protected static function getFormSchema(): array
    {
        return [
            Section::make('Informasi Rekam Medis')
                ->schema([
                    Forms\Components\TextInput::make('medical_record_number')
                        ->label('No. Rekam Medis')
                        ->placeholder('Akan digenerate otomatis jika kosong')
                        ->unique(ignoreRecord: true)
                        ->maxLength(20),
                ]),

            Section::make('Data Pribadi')
                ->schema([
                    Grid::make(2)->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Lengkap')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true),
                    ]),
                    Grid::make(2)->schema([
                        Forms\Components\TextInput::make('nomor_ktp')
                            ->label('Nomor KTP/NIK')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->length(16),
                        Forms\Components\TextInput::make('phone')
                            ->label('No. Telepon')
                            ->tel()
                            ->unique(ignoreRecord: true),
                    ]),
                    Grid::make(2)->schema([
                        Forms\Components\DatePicker::make('birth_date')
                            ->label('Tanggal Lahir')
                            ->maxDate(now()),
                        Forms\Components\Select::make('gender')
                            ->label('Jenis Kelamin')
                            ->options([
                                'Laki-laki' => 'Laki-laki',
                                'Perempuan' => 'Perempuan',
                            ]),
                    ]),
                ]),

            Section::make('Alamat & Password')
                ->schema([
                    Forms\Components\Textarea::make('address')
                        ->label('Alamat Lengkap')
                        ->rows(3),
                    Forms\Components\TextInput::make('password')
                        ->label('Password Baru')
                        ->password()
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->minLength(6),
                    Forms\Components\Hidden::make('role')->default('user'),
                ]),
        ];
    }

    protected static function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('medical_record_number')
                ->label('No. RM')
                ->searchable()
                ->sortable()
                ->badge()
                ->color('primary')
                ->copyable(),

            Tables\Columns\TextColumn::make('name')
                ->label('Nama Pasien')
                ->searchable()
                ->sortable()
                ->weight('semibold')
                ->description(fn (User $record) => $record->email),

            Tables\Columns\TextColumn::make('phone')
                ->label('No. HP')
                ->searchable()
                ->copyable()
                ->placeholder('Belum ada'),

            Tables\Columns\TextColumn::make('gender')
                ->label('Gender')
                ->badge()
                ->color(fn (?string $state) => match ($state) {
                    'Laki-laki' => 'blue',
                    'Perempuan' => 'pink',
                    default => 'gray',
                }),

            Tables\Columns\TextColumn::make('created_at')
                ->label('Terdaftar')
                ->dateTime('d/m/Y')
                ->sortable()
                ->since(),
        ];
    }

    protected static function getTableFilters(): array
    {
        return [
            Tables\Filters\SelectFilter::make('gender')
                ->options([
                    'Laki-laki' => 'Laki-laki',
                    'Perempuan' => 'Perempuan',
                ]),

            Tables\Filters\Filter::make('has_mrn')
                ->label('Punya No. RM')
                ->query(fn ($query) => $query->whereNotNull('medical_record_number')),

            Tables\Filters\Filter::make('no_mrn')
                ->label('Belum ada No. RM')
                ->query(fn ($query) => $query->whereNull('medical_record_number')),
        ];
    }

    protected static function getTableActions(): array
    {
        return [
            Tables\Actions\ActionGroup::make([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                
                Tables\Actions\Action::make('generate_mrn')
                    ->label('Generate No. RM')
                    ->icon('heroicon-o-identification')
                    ->color('success')
                    ->visible(fn (User $record) => !$record->medical_record_number)
                    ->action(function (User $record) {
                        $record->assignMedicalRecordNumber();
                        Notification::make()
                            ->title('No. RM Generated')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reset_password')
                    ->label('Reset Password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('new_password')
                            ->label('Password Baru')
                            ->password()
                            ->required()
                            ->minLength(6),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update(['password' => bcrypt($data['new_password'])]);
                        Notification::make()
                            ->title('Password Updated')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make(),
            ])
        ];
    }

    protected static function getBulkActions(): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\BulkAction::make('generate_mrn_bulk')
                    ->label('Generate No. RM')
                    ->action(function ($records) {
                        $count = 0;
                        foreach ($records as $record) {
                            if (!$record->medical_record_number) {
                                $record->assignMedicalRecordNumber();
                                $count++;
                            }
                        }
                        Notification::make()
                            ->title("{$count} No. RM Generated")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteBulkAction::make(),
            ]),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPatientManagement::route('/'),
            'create' => Pages\CreatePatientManagement::route('/create'),
            'view' => Pages\ViewPatientManagement::route('/{record}'),
            'edit' => Pages\EditPatientManagement::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('role', 'user')->count() ?: null;
    }
}