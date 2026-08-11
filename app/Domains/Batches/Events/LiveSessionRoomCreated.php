<?php

namespace App\Domains\Batches\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveSessionRoomCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $batchSessionId,
        public readonly int $tenantId,
    ) {}
}
