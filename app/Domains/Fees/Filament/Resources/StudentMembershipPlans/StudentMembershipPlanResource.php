<?php

namespace App\Domains\Fees\Filament\Resources\StudentMembershipPlans;

use App\Domains\Fees\Filament\Resources\StudentMembershipPlans\Pages\CreateStudentMembershipPlan;
use App\Domains\Fees\Filament\Resources\StudentMembershipPlans\Pages\EditStudentMembershipPlan;
use App\Domains\Fees\Filament\Resources\StudentMembershipPlans\Pages\ListStudentMembershipPlans;
use App\Domains\Fees\Models\StudentMembershipPlan;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S20 P9 pt.1 — an institute's own membership catalog builder, same
 * resource shape `SurveyResource` (S19) established for authoring a
 * tenant-scoped catalog. Checkout/subscription lifecycle is B4's own
 * concern; this block only authors the plan itself.
 */
class StudentMembershipPlanResource extends Resource
{
    protected static ?string $model = StudentMembershipPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Membership plans';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('price')
                ->numeric()
                ->minValue(0)
                ->required(),
            Select::make('billing_interval')
                ->options(array_combine(StudentMembershipPlan::BILLING_INTERVALS, StudentMembershipPlan::BILLING_INTERVALS))
                ->required()
                ->native(false),
            TextInput::make('trial_days')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->helperText('0 for no free trial.')
                ->required(),
            Select::make('status')
                ->options(array_combine(StudentMembershipPlan::STATUSES, StudentMembershipPlan::STATUSES))
                ->default('draft')
                ->required()
                ->native(false)
                ->helperText('Only an active plan is offered to students.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('price')
                    ->formatStateUsing(fn (StudentMembershipPlan $record): string => '₹'.number_format((float) $record->price, 2)),
                TextColumn::make('billing_interval')->badge(),
                TextColumn::make('trial_days')->label('Trial days'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'archived' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(StudentMembershipPlan::STATUSES, StudentMembershipPlan::STATUSES)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentMembershipPlans::route('/'),
            'create' => CreateStudentMembershipPlan::route('/create'),
            'edit' => EditStudentMembershipPlan::route('/{record}/edit'),
        ];
    }
}
