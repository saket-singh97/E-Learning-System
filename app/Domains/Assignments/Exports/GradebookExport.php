<?php

namespace App\Domains\Assignments\Exports;

use App\Domains\Assignments\Jobs\Concerns\BuildsGradebookRows;
use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * First use of maatwebsite/excel (^4.0) in this app. CSV formula injection
 * (CWE-1236) is not a CSV-only risk — a cell starting with =/+/-/@ is
 * exactly as live in a real .xlsx opened in Excel/Sheets — so this reuses
 * EscapesCsvFormulas's own csvSafe() rather than treating the guard as
 * CSV-specific, same trait ExportGradebookCsv (B9) already established for
 * the identical reason.
 */
class GradebookExport implements FromCollection, WithHeadings
{
    use BuildsGradebookRows, EscapesCsvFormulas;

    public function __construct(
        public readonly int $tenantId,
    ) {}

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Student name', 'Student email', 'Course', 'Quiz %', 'Assignment %', 'Weighted %', 'Standing'];
    }

    public function collection(): Collection
    {
        return $this->gradebookRows($this->tenantId)->map(fn (array $row): array => [
            $this->csvSafe($row['name']),
            $this->csvSafe($row['email']),
            $this->csvSafe($row['course']),
            $row['quiz_percent'],
            $row['assignment_percent'],
            $row['weighted_percent'],
            $row['standing'],
        ]);
    }
}
