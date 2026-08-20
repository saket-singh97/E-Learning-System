<?php

namespace App\Domains\Content\Models;

use App\Domains\Batches\Models\Batch;
use App\Support\Security\SanitizesHtml;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Program extends Model
{
    use BelongsToTenant, SanitizesHtml, SoftDeletes;

    public const STATUSES = ['draft', 'published'];

    protected $fillable = [
        'tenant_id',
        'title',
        'slug',
        'description',
        'price',
        'status',
    ];

    protected array $sanitizable = [
        'description',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class, 'program_batch');
    }
}
