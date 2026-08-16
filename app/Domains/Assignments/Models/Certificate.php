<?php

namespace App\Domains\Assignments\Models;

use App\Domains\Content\Models\Course;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'course_id',
        'verification_id',
        'final_score',
        'pdf_disk_path',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'final_score' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
