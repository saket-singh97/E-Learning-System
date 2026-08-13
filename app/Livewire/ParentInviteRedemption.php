<?php

namespace App\Livewire;

use App\Domains\Comms\Services\ParentInviteCodeService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Host-agnostic (routes/web.php, no domain constraint) — a prospective
 * parent's link/printed code isn't tied to any particular subdomain, and
 * the code itself carries the tenant (ParentInviteCodeService's own
 * docblock). Logging in here still works across any *.lms.test subdomain
 * once redeemed, since SESSION_DOMAIN is a shared parent domain — the same
 * "shared session is the feature, not the leak" posture S9 B2's Google
 * OAuth flow already established.
 */
class ParentInviteRedemption extends Component
{
    public string $code = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $relationship = '';

    public bool $redeemed = false;

    public function redeem(): void
    {
        $this->validate([
            'code' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        try {
            $parent = app(ParentInviteCodeService::class)->redeem($this->code, [
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'password' => $this->password,
                'relationship' => $this->relationship ?: null,
            ]);
        } catch (ValidationException $e) {
            $this->addError('code', $e->validator->errors()->first('code'));

            return;
        }

        Auth::login($parent);
        session()->regenerate();

        $this->redeemed = true;
        $this->redirect("http://{$parent->tenant->subdomain}.lms.test/parent/dashboard");
    }

    public function render(): View
    {
        return view('livewire.parent-invite-redemption');
    }
}
