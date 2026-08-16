<?php

namespace App\Filament\Admin\Pages;

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
 * `/admin`'s tenant-wide counterpart to `/teach`'s `App\Filament\Teach\Pages\Gradebook`
 * (same class name, different panel namespace — Filament panels don't share
 * page classes, matching how CourseResource/BatchResource etc. are also
 * registered per-panel independently). See that class's own docblock for
 * the full reasoning (one row per Enrolment, not per student; not truly
 * DB-sortable, matching StudentProgress's own precedent; "per-course" read
 * as "course-filterable" for the identical no-instructor-pivot reason;
 * J7's three export formats live here as header actions, one shared
 * job/route set both panels point at).
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
        // Same whereHas guard as /teach's own Gradebook (S10 B15 review
        // pass) — Batch/Course are both SoftDeletes with no delete guard
        // against active enrolments, so a stale enrolment can point at a
        // null relation otherwise.
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
