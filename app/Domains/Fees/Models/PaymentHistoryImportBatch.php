<?php

namespace App\Domains\Fees\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentHistoryImportBatch extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending', 'dry_run', 'completed', 'failed'];

    protected $fillable = [
        'tenant_id',
        'uploaded_by_user_id',
        'status',
        'total_rows',
        'error_rows',
        'dry_run',
        'error_report_path',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'error_rows' => 'integer',
            'dry_run' => 'boolean',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
