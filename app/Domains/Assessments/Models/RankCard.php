<?php

namespace App\Domains\Assessments\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankCard extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'test_series_id',
        'user_id',
        'pdf_path',
    ];

    public function testSeries(): BelongsTo
    {
        return $this->belongsTo(TestSeries::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
