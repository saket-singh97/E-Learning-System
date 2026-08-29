<?php

namespace App\Domains\Content\Services;

use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseImportBatch;
use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Exceptions\InvalidCourseImportFileException;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * S21 B10 (V14 pt.1, design doc §4 decision 7) — same dry-run/per-row-error
 * shape StudentCsvImporter (S15) established, but course creation is cheap
 * (no external I/O, no role-assignment policy checks) so this runs fully
 * synchronously, dry-run or real — no queued phase 2 the way the heavier
 * student import needed one.
 */
class CourseCsvImporter
{
    use EscapesCsvFormulas;

    private const REQUIRED_HEADERS = ['title', 'slug', 'category'];

    public function import(string $csvContents, User $uploader, bool $dryRun): CourseImportBatch
    {
        $csvContents = preg_replace('/^\xEF\xBB\xBF/', '', $csvContents);

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', trim($csvContents)),
            fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            throw InvalidCourseImportFileException::empty();
        }

        $header = array_map(fn (string $h): string => strtolower(trim($h)), str_getcsv(array_shift($lines)));

        if (array_diff(self::REQUIRED_HEADERS, $header) !== []) {
            throw InvalidCourseImportFileException::badHeaders();
        }

        if ($lines === []) {
            throw InvalidCourseImportFileException::empty();
        }

        $valid = [];
        $errors = [];
        $seenSlugs = [];

        foreach ($lines as $index => $line) {
            $rowNumber = $index + 2;
            $values = str_getcsv($line);

            if (count($values) !== count($header)) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Row has a different number of columns than the header.'];

                continue;
            }

            $fields = array_combine($header, $values);

            $title = trim((string) ($fields['title'] ?? ''));
            $slug = Str::slug(trim((string) ($fields['slug'] ?? '')));
            $category = trim((string) ($fields['category'] ?? ''));

            if ($title === '') {
                $errors[] = ['row' => $rowNumber, 'message' => 'Title is required.'];

                continue;
            }

            if ($slug === '') {
                $errors[] = ['row' => $rowNumber, 'message' => 'Slug is required.'];

                continue;
            }

            if (isset($seenSlugs[$slug])) {
                $errors[] = ['row' => $rowNumber, 'message' => "Duplicate slug in this file (also row {$seenSlugs[$slug]})."];

                continue;
            }

            if (Course::where('slug', $slug)->exists()) {
                $errors[] = ['row' => $rowNumber, 'message' => "Slug \"{$slug}\" is already in use."];

                continue;
            }

            $seenSlugs[$slug] = $rowNumber;

            $valid[] = ['row' => $rowNumber, 'title' => $title, 'slug' => $slug, 'category' => $category !== '' ? $category : null];
        }

        $successCount = 0;

        if (! $dryRun) {
            foreach ($valid as $row) {
                $course = Course::create(['title' => $row['title'], 'slug' => $row['slug'], 'status' => 'draft']);

                if ($row['category'] !== null) {
                    $category = Category::firstOrCreate(
                        ['slug' => Str::slug($row['category'])],
                        ['name' => $row['category']],
                    );

                    $course->categories()->attach($category->id);
                }

                $successCount++;
            }
        }

        $reportPath = null;

        if ($errors !== []) {
            $reportPath = "course-imports/{$uploader->tenant_id}-".uniqid().'-errors.csv';
            Storage::disk('local')->put($reportPath, $this->buildErrorReportCsv($errors));
        }

        return CourseImportBatch::create([
            'uploaded_by_user_id' => $uploader->id,
            'status' => $dryRun ? 'dry_run' : 'completed',
            'total_rows' => count($lines),
            'error_rows' => count($errors),
            'dry_run' => $dryRun,
            'error_report_path' => $reportPath,
        ]);
    }

    /**
     * @param  array<int, array{row: int, message: string}>  $errors
     */
    protected function buildErrorReportCsv(array $errors): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Row', 'Error']);

        foreach ($errors as $error) {
            fputcsv($handle, [$error['row'], $this->csvSafe($error['message'])]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
