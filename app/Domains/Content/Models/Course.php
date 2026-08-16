<?php

namespace App\Domains\Content\Models;

use App\Support\Security\SanitizesHtml;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use BelongsToTenant, SanitizesHtml, SoftDeletes;

    public const STATUSES = ['draft', 'published', 'archived'];

    protected $fillable = [
        'tenant_id',
        'title',
        'slug',
        'description',
        'status',
        'gradebook_weights',
        'passing_threshold',
    ];

    protected array $sanitizable = [
        'description',
    ];

    protected function casts(): array
    {
        return [
            'gradebook_weights' => 'array',
            'passing_threshold' => 'decimal:2',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('position');
    }
}
