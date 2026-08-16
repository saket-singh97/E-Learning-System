<?php

namespace App\Domains\Assignments\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmission extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'assignment_id',
        'user_id',
        'version_number',
        'files',
        'submitted_at',
        'is_late',
        'marks_awarded',
        'feedback',
        'rubric_scores',
        'graded_by_user_id',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'files' => 'array',
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
            'marks_awarded' => 'decimal:2',
            'rubric_scores' => 'array',
            'graded_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gradedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by_user_id');
    }
}
