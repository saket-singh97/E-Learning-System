<?php

namespace App\Filament\Teach\Pages;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Services\StudentMetrics;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * This codebase's first standalone Filament Page with a table not tied to
 * a Resource's own CRUD — no in-repo precedent existed to copy, built from
 * Filament\Resources\Pages\ManageRelatedRecords's own use of
 * EmbeddedTable::make() inside content(), the framework's generic
 * "a Livewire component implementing HasTable can embed its table via a
 * schema component" mechanism.
 *
 * "Own batches" has no backing schema to enforce — no instructor-batch/
 * course pivot exists anywhere in this codebase, confirmed by reading
 * every Batch/Course-related model and policy. EnrolmentApprovalPolicy's
 * own docblock already documents this exact gap and reads "assigned" as
 * "same tenant as the batch" instead of a real per-instructor link (S4).
 * Followed here for consistency — the roster below is every actively
 * enrolled student in the instructor's own tenant, not a stricter scope
 * this app has no data to support yet.
 *
 * Metrics are read per row via StudentMetrics (S8 B2), built explicitly
 * for this reuse. No caching layer added — Filament's own table
 * pagination already bounds this to one page's worth of students per
 * request, and this app's standing posture is no new cached-aggregation
 * layer until a real demo-scale volume proves it's needed (design doc
 * §2.4 decision 6's own "M16 deferred on that basis" note).
 */
class StudentProgress extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Student Progress';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->studentsQuery())
            ->columns([
                TextColumn::make('name')->label('Student')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('attendance')
                    ->label('Attendance')
                    ->state(fn (User $record): ?float => $this->metricsFor($record)['attendance_percent'])
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1).'%')
                    ->placeholder('—'),
                TextColumn::make('coverage')
                    ->label('Coverage')
                    ->state(fn (User $record): ?float => $this->metricsFor($record)['coverage_percent'])
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1).'%')
                    ->placeholder('—'),
                TextColumn::make('quiz_average')
                    ->label('Quiz average')
                    ->state(fn (User $record): ?float => $this->metricsFor($record)['quiz_average_percent'])
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1).'%')
                    ->placeholder('—'),
                TextColumn::make('fee_status')
                    ->label('Fee status')
                    ->state(fn (User $record): string => $this->metricsFor($record)['fee_status'])
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state)))
                    ->color(fn (string $state): string => match ($state) {
                        'overdue' => 'danger',
                        'pending' => 'warning',
                        default => 'success',
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function studentsQuery(): Builder
    {
        return User::role('student')
            ->whereIn('id', Enrolment::where('status', 'active')->pluck('user_id'))
            ->when(
                app(CurrentTenant::class)->isBound(),
                fn (Builder $query) => $query->where('tenant_id', app(CurrentTenant::class)->id()),
            );
    }

    /**
     * @return array{attendance_percent: ?float, coverage_percent: ?float, quiz_average_percent: ?float, fee_status: string, outstanding_invoices: \Illuminate\Support\Collection}
     */
    protected function metricsFor(User $student): array
    {
        return app(StudentMetrics::class)->summarize($student);
    }
}
