<?php

namespace App\Domains\Content\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonQuestionVote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'lesson_question_id',
        'user_id',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(LessonQuestion::class, 'lesson_question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
