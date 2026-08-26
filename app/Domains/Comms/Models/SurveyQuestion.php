<?php

namespace App\Domains\Comms\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S19 N15 — `options` carries each type's own shape by convention (design
 * doc §4 decision 6, same "no schema migration per type" property
 * `Question::correct_answer` already has): `single_choice`/`multi_choice`
 * → `{choices: [...]}`, `rating` → `{max: N}`, `text` → null.
 */
class SurveyQuestion extends Model
{
    use BelongsToTenant;

    public const TYPES = ['text', 'single_choice', 'multi_choice', 'rating'];

    protected $fillable = [
        'tenant_id',
        'survey_id',
        'prompt',
        'type',
        'options',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'position' => 'integer',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
