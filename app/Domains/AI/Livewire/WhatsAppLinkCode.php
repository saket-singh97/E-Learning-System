<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Models\WhatsAppLink;
use App\Domains\AI\Services\WhatsAppLinkService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Student-portal side of design decision 1's linking flow: generates the
 * one-time code the student then texts to the platform's WhatsApp number.
 * Verification itself happens over the inbound webhook (B1), not here.
 */
class WhatsAppLinkCode extends Component
{
    public ?string $code = null;

    public function generate(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $this->code = app(WhatsAppLinkService::class)->generateCode($user);
    }

    public function render(): View
    {
        $user = auth()->user();

        $link = $user === null
            ? null
            : WhatsAppLink::where('user_id', $user->id)->first();

        return view('ai::whatsapp-link-code', ['link' => $link]);
    }
}
