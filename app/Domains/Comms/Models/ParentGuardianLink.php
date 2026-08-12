<?php

namespace App\Domains\Comms\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentGuardianLink extends Model
{
    use BelongsToTenant;

    /**
     * Class name doesn't match the table's design-doc-pinned name — Eloquent
     * would otherwise guess parent_guardian_links.
     */
    protected $table = 'parent_student_links';

    protected $fillable = [
        'tenant_id', 'parent_user_id', 'student_user_id', 'relationship',
    ];

    public function parentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }
}
