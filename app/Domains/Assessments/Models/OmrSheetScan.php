<?php

namespace App\Domains\Assessments\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S16 B14/B15 (I11) — one uploaded bubble-sheet image per row. `status`
 * stays `pending` until `OmrScanner` (B15) processes it; a roll number
 * that doesn't resolve to a real enrolment queues `failed` for manual
 * admin resolution rather than being silently dropped (design doc §4
 * decision 9).
 */
class OmrSheetScan extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'processed', 'failed'];

    protected $fillable = [
        'tenant_id',
        'omr_template_id',
        'uploaded_by_user_id',
        'image_disk_path',
        'status',
        'detected_roll_number',
        'matched_user_id',
        'quiz_attempt_id',
        'raw_bubble_results',
    ];

    protected function casts(): array
    {
        return [
            'raw_bubble_results' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OmrTemplate::class, 'omr_template_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function matchedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_user_id');
    }

    public function quizAttempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class);
    }
}
