<?php

namespace App\Domains\AI\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppLink extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_links';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'phone_e164',
        'verified_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
