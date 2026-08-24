<?php

namespace App\Filament\Teach\Pages;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Services\StudentMetrics;
use App\Models\User;
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
use Illuminate\Support\Collection;

/**
 * S16 B1 (H10) — instructor-facing counterpart to Admin's tenant-wide
 * AtRiskStudents, scoped to the instructor's own assigned batches, same
 * `batches.instructor_id` scoping StudentProgress (S15 B2) already
 * established.
 */
class AtRiskStudents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'At-Risk Students';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->atRiskStudentsQuery())
            ->columns([
                TextColumn::make('name')->label('Student')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('attendance')
                    ->label('Attendance')
                    ->state(fn (User $record): ?float => $this->metricsFor($record)['attendance_percent'])
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : number_format($state, 1).'%'),
                TextColumn::make('coverage')
                    ->label('Coverage')
                    ->state(fn (User $record): ?float => $this->metricsFor($record)['coverage_percent'])
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : number_format($state, 1).'%'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function atRiskStudentsQuery(): Builder
    {
        $metrics = app(StudentMetrics::class);

        $atRiskIds = $this->ownBatchStudents()
            ->filter(fn (User $student): bool => $metrics->isAtRisk($metrics->summarize($student), $student))
            ->pluck('id');

        return User::whereIn('id', $atRiskIds);
    }

    protected function ownBatchStudents(): Collection
    {
        $batchIds = Batch::where('instructor_id', auth()->id())->pluck('id');

        return User::role('student')
            ->whereIn('id', Enrolment::where('status', 'active')->whereIn('batch_id', $batchIds)->pluck('user_id'))
            ->get();
    }

    /**
     * @return array{attendance_percent: ?float, coverage_percent: ?float, quiz_average_percent: ?float, fee_status: string, outstanding_invoices: Collection}
     */
    protected function metricsFor(User $student): array
    {
        return app(StudentMetrics::class)->summarize($student);
    }
}
