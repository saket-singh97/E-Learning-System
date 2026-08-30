<?php

namespace App\Domains\Growth\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A prospect isn't a user yet, so this takes a bare name/email/phone, no
 * user_id — same shape as CourseInterestSignup (design doc §3.2).
 */
class ScholarshipTestRegistration extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'scholarship_test_id',
        'name',
        'email',
        'phone',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    public function scholarshipTest(): BelongsTo
    {
        return $this->belongsTo(ScholarshipTest::class);
    }
}
