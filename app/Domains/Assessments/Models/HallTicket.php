<?php

namespace App\Domains\Assessments\Models;

use App\Domains\Growth\Models\ScholarshipTestRegistration;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assessments domain per design doc §3.1 ("U14, U15 | Question/TestSeries
 * extensions and their PDF outputs"), even though it belongs to a Growth
 * model — cross-domain model read, same precedent Question::lesson()
 * already established (Assessments -> Content), just the other direction.
 */
class HallTicket extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'scholarship_test_registration_id',
        'roll_number',
        'pdf_path',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ScholarshipTestRegistration::class, 'scholarship_test_registration_id');
    }
}
