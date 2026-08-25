<?php

namespace App\Domains\AI\Models;

use App\Domains\Content\Models\Course;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseOutlineDraft extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'approved', 'rejected'];

    protected $fillable = [
        'tenant_id',
        'created_by_user_id',
        'topic',
        'target_level',
        'status',
        'generated_outline',
        'created_course_id',
        'token_cost',
    ];

    protected function casts(): array
    {
        return [
            'generated_outline' => 'array',
            'token_cost' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function createdCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'created_course_id');
    }
}
