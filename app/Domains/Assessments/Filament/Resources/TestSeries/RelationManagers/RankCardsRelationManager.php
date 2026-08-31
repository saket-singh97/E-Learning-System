<?php

namespace App\Domains\Assessments\Filament\Resources\TestSeries\RelationManagers;

use App\Domains\Assessments\Models\RankCard;
use App\Domains\Assessments\Services\RankCardGenerator;
use App\Domains\Assessments\Services\TestSeriesRankingService;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S23 B16 (U15 pt.2) — Admin bulk-view/regenerate for rank cards, browse
 * only (every row comes from RankCardGenerator, never a manual admin
 * entry, same posture CourseInterestSignupResource already takes). The
 * header action regenerates every current participant's card in one pass
 * — RankCardGenerator::generate() itself already overwrites on repeat call
 * (design §4 decision 8), so this is safe to click again after new
 * attempts land.
 */
class RankCardsRelationManager extends RelationManager
{
    protected static string $relationship = 'rankCards';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('user.name')->label('Student')->searchable(),
                TextColumn::make('user.email')->searchable(),
                TextColumn::make('created_at')->label('Generated')->dateTime()->sortable(),
            ])
            ->headerActions([
                Action::make('generateAll')
                    ->label('Generate/regenerate all rank cards')
                    ->action(function (): void {
                        $series = $this->getOwnerRecord();
                        $generator = app(RankCardGenerator::class);

                        app(TestSeriesRankingService::class)
                            ->rankingsFor($series, auth()->user())
                            ->each(function (array $row) use ($series, $generator): void {
                                $user = User::find($row['user_id']);

                                if ($user !== null) {
                                    $generator->generate($series, $user);
                                }
                            });
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->url(fn (RankCard $record) => route('rank-cards.download', [
                        'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                        'rankCard' => $record->id,
                    ]))
                    ->openUrlInNewTab(),
            ]);
    }
}
