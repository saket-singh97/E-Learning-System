<?php

namespace App\Filament\Teach\Pages;

use App\Domains\Assignments\Jobs\ExportGradebookCsv;
use App\Domains\Assignments\Jobs\ExportGradebookExcel;
use App\Domains\Assignments\Jobs\ExportGradebookPdf;
use App\Domains\Assignments\Services\GradebookService;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * J5's gradebook table — same "standalone Filament Page implementing
 * HasTable, EmbeddedTable::make() in content()" shape StudentProgress (S8
 * B5) established, this app's only prior precedent for a table-only page.
 * One row per active Enrolment (not per student, unlike StudentProgress) —
 * a gradebook score is always per-course (GradebookService::scoreFor()
 * takes a Course), so a student enrolled in two courses gets two rows.
 *
 * The plan note's own "/teach: per-course... /admin: tenant-wide,
 * course-filterable" phrasing is read here as "same shape, both
 * course-filterable" (deviation, recorded): no instructor-course pivot
 * exists anywhere in this app to enforce a genuinely narrower /teach scope
 * — the same reading HANDOVER's own "Avoid" section already applied
 * repeatedly (S4, S8×2) to every other "instructor's own X" surface.
 *
 * Not truly DB-sortable by score, matching StudentProgress's own
 * precedent: FEATURES.md's M11 also promised "sortable" for that page,
 * but the actual built file never added ->sortable() to a computed
 * state()-backed column (confirmed by reading it) — a weighted score
 * spans four tables with no materialized column to sort on, and
 * StudentProgress already established this app's accepted posture for
 * that exact tradeoff.
 *
 * J7's three export formats (CSV B9, Excel/PDF B10) all live here as
 * header actions rather than a separate page — same generate/download
 * pair EngagementExport (S9 B11) established, just attached to the table
 * they export instead of a standalone export-only screen.
 */
class Gradebook extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $navigationLabel = 'Gradebook';

    protected function getHeaderActions(): array
    {
        return [
            ...$this->exportActions('CSV', ExportGradebookCsv::class, 'assignments.gradebook-export.download'),
            ...$this->exportActions('Excel', ExportGradebookExcel::class, 'assignments.gradebook-excel-export.download'),
            ...$this->exportActions('PDF', ExportGradebookPdf::class, 'assignments.gradebook-pdf-export.download'),
        ];
    }

    /**
     * One generate/download action pair per format — extracted once a
     * third export format made three near-identical inline blocks the
     * same "copy waiting to be forgotten" shape every other extraction in
     * this slice (EscapesCsvFormulas, GradebookService::standingFor(),
     * BuildsGradebookRows) already caught.
     *
     * @param  class-string  $jobClass
     * @return array<int, Action>
     */
    protected function exportActions(string $label, string $jobClass, string $routeName): array
    {
        return [
            Action::make("generate{$label}Export")
                ->label("Generate {$label} export")
                ->action(function () use ($jobClass): void {
                    $jobClass::dispatch(app(CurrentTenant::class)->id());

                    Notification::make()
                        ->title('Export queued — refresh this page in a moment to download it.')
                        ->success()
                        ->send();
                }),
            Action::make("download{$label}Export")
                ->label("Download {$label} export")
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route($routeName, [
                    'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                ]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => Storage::disk('local')->exists($jobClass::pathFor(app(CurrentTenant::class)->id()))),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->enrolmentsQuery())
            ->columns([
                TextColumn::make('user.name')->label('Student')->searchable(),
                TextColumn::make('batch.course.title')->label('Course')->searchable(),
                TextColumn::make('quiz_percent')
                    ->label('Quiz %')
                    ->state(fn (Enrolment $record): ?float => $this->scoreFor($record)['quiz_percent'])
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1).'%')
                    ->placeholder('—'),
                TextColumn::make('assignment_percent')
                    ->label('Assignment %')
                    ->state(fn (Enrolment $record): ?float => $this->scoreFor($record)['assignment_percent'])
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1).'%')
                    ->placeholder('—'),
                TextColumn::make('weighted_percent')
                    ->label('Weighted score')
                    ->state(fn (Enrolment $record): float => $this->scoreFor($record)['weighted_percent'])
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1).'%'),
                TextColumn::make('standing')
                    ->label('Standing')
                    ->state(fn (Enrolment $record): string => app(GradebookService::class)->standingFor($this->scoreFor($record)))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Failing' => 'danger',
                        'At risk' => 'warning',
                        'No data yet' => 'gray',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('course')
                    ->label('Course')
                    ->options(fn (): array => Course::pluck('title', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $courseId) => $q->whereHas('batch', fn (Builder $bq) => $bq->where('course_id', $courseId)),
                    )),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function enrolmentsQuery(): Builder
    {
        // Enrolment's own BelongsToTenant global scope already narrows this
        // to the current tenant — no manual CurrentTenant check needed,
        // unlike StudentProgress's User::query() (User isn't
        // BelongsToTenant-scoped the same automatic way).
        //
        // whereHas('batch', ...->whereHas('course')) (S10 B15 review pass):
        // Batch and Course are both SoftDeletes, and BatchResource's own
        // DeleteAction has no guard against active enrolments — an active
        // Enrolment can point at a soft-deleted batch (or a batch whose own
        // course was soft-deleted), and every column below reads
        // ->batch->course unconditionally. Filtered out here instead of
        // null-checked in each column closure.
        return Enrolment::where('status', 'active')
            ->whereHas('batch', fn (Builder $query) => $query->whereHas('course'))
            ->with(['user', 'batch.course']);
    }

    /**
     * @return array{quiz_percent: ?float, assignment_percent: ?float, weighted_percent: float, is_passing: bool, is_at_risk: bool}
     */
    protected function scoreFor(Enrolment $enrolment): array
    {
        return app(GradebookService::class)->scoreFor($enrolment->user, $enrolment->batch->course);
    }
}
