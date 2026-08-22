<?php

namespace App\Domains\Batches\Filament\Resources\Batches\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * F8 pt.2 — read-only aggregation view (no create/edit/delete actions):
 * feedback is student-submitted only (BatchFeedbackForm, B7), this
 * relation manager is instructor/admin's own view of it, reachable from
 * both panels since BatchResource itself already is (B2).
 */
class FeedbackRelationManager extends RelationManager
{
    protected static string $relationship = 'feedback';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('user.name')->label('Student'),
                TextColumn::make('rating')
                    ->label('Rating')
                    ->suffix('/5')
                    ->summarize(Average::make()->label('Average rating')),
                TextColumn::make('comment')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }
}
