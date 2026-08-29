<?php

namespace App\Domains\Assessments\Models;

use App\Domains\Batches\Models\Batch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPracticeSet extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'practice_date',
        'question_ids',
    ];

    protected function casts(): array
    {
        return [
            'practice_date' => 'date',
            'question_ids' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
