<?php

namespace App\Domains\Operations\Filament\Resources\Enquiries;

use App\Domains\Batches\Models\Batch;
use App\Domains\Content\Models\Course;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\CreateEnquiry;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\EditEnquiry;
use App\Domains\Operations\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Domains\Operations\Filament\Resources\Enquiries\RelationManagers\FollowUpsRelationManager;
use App\Domains\Operations\Models\Enquiry;
use App\Domains\Operations\Services\EnquiryConversionService;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Admission CRM intake + list (S.S4 pt.1) — reachable from both Admin
 * and Teach (directory-wide discovery on both panels), no per-instructor
 * scoping: enquiries have an `assigned_to_user_id` for CRM ownership, not
 * a batch-style security boundary the way BatchResource's own
 * `instructor_id` scoping (B2) is.
 */
class EnquiryResource extends Resource
{
    protected static ?string $model = Enquiry::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required(),
            TextInput::make('phone')
                ->tel(),
            Select::make('course_id')
                ->label('Course interest')
                ->options(fn (): array => Course::query()->pluck('title', 'id')->all())
                ->searchable(),
            Select::make('status')
                ->options(array_combine(Enquiry::STATUSES, Enquiry::STATUSES))
                ->default('new')
                ->required()
                ->native(false),
            TextInput::make('source')
                ->helperText('e.g. website, walk-in, referral.'),
            Select::make('assigned_to_user_id')
                ->label('Assigned to')
                ->options(fn (): array => User::role(['institute_admin', 'instructor'])
                    ->where('tenant_id', app(CurrentTenant::class)->id())
                    ->pluck('name', 'id')
                    ->all())
                ->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('course.title')->label('Interested in')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'converted' => 'success',
                        'lost' => 'danger',
                        'follow_up' => 'warning',
                        'contacted' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('assignedTo.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(Enquiry::STATUSES, Enquiry::STATUSES)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                // S.S4 pt.2, design decision 9 — writes a real Enrolment
                // through BatchEnrolmentService, not a parallel path.
                Action::make('convertToEnrolment')
                    ->label('Convert to enrolment')
                    ->requiresConfirmation()
                    ->visible(fn (Enquiry $record): bool => $record->status !== 'converted')
                    ->schema([
                        Select::make('batch_id')
                            ->label('Batch')
                            ->options(fn (Enquiry $record): array => Batch::query()
                                ->when($record->course_id, fn ($query) => $query->where('course_id', $record->course_id))
                                ->pluck('title', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Enquiry $record, array $data): void {
                        app(EnquiryConversionService::class)->convert(
                            $record,
                            Batch::findOrFail($data['batch_id']),
                            auth()->user(),
                        );

                        Notification::make()->title('Enquiry converted to enrolment')->success()->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            FollowUpsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnquiries::route('/'),
            'create' => CreateEnquiry::route('/create'),
            'edit' => EditEnquiry::route('/{record}/edit'),
        ];
    }
}
