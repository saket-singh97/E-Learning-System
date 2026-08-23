<?php

namespace App\Domains\Operations\Filament\Resources\Enquiries\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FollowUpsRelationManager extends RelationManager
{
    protected static string $relationship = 'followUps';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('note')
                ->required()
                ->maxLength(2000),
            DateTimePicker::make('next_follow_up_at')
                ->label('Next follow-up reminder')
                ->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('note')
            ->columns([
                TextColumn::make('note')->wrap(),
                TextColumn::make('next_follow_up_at')->dateTime()->placeholder('—'),
                TextColumn::make('user.name')->label('Logged by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = auth()->id();

                        return $data;
                    }),
            ]);
    }
}
