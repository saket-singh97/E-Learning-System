<?php

namespace App\Domains\Growth\Filament\Resources\ScholarshipTests\RelationManagers;

use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Domains\Growth\Services\ScholarshipConversionService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Browse-only — every row comes from the public registration page (B12),
 * never a manual admin entry, same posture CourseInterestSignupResource
 * already takes. Score/conversion is the results view the plan block asks
 * for; conversion is computed per row via ScholarshipConversionService
 * (design §4 decision 3's query-not-event posture), not stored.
 */
class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('score')->numeric(2)->sortable()->placeholder('Not graded'),
                IconColumn::make('converted')
                    ->label('Converted')
                    ->boolean()
                    ->getStateUsing(fn (ScholarshipTestRegistration $record): bool => app(ScholarshipConversionService::class)->hasConverted($record)),
                TextColumn::make('created_at')->label('Registered')->dateTime()->sortable(),
            ])
            ->defaultSort('score', 'desc');
    }
}
