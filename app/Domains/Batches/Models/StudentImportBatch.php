<?php

namespace App\Domains\Batches\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks one CSV upload through both phases (design decision 6) —
 * total_rows/status are set by phase 1 (StudentCsvImporter, synchronous);
 * success_count/error_count/error_report_disk_path are filled in by
 * phase 2 (the queued creation job, B10) once rows are actually applied.
 */
class StudentImportBatch extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'processing', 'done', 'failed'];

    protected $fillable = [
        'tenant_id',
        'uploaded_by_user_id',
        'status',
        'total_rows',
        'success_count',
        'error_count',
        'error_report_disk_path',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'success_count' => 'integer',
            'error_count' => 'integer',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
