<?php

namespace App\Livewire;

use App\Domains\Tenancy\Models\InstructorInvitation;
use App\Domains\Tenancy\Services\InstructorInvitationService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Host-agnostic (routes/web.php, no domain constraint) — same "the token
 * carries the tenant, not the host" shape ParentInviteRedemption already
 * established (InstructorInvitationService::accept()'s own docblock).
 */
class InstructorInvitationAccept extends Component
{
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public bool $accepted = false;

    public bool $userAlreadyExists = false;

    public function mount(string $token): void
    {
        $this->token = $token;

        $invitation = InstructorInvitation::withoutGlobalScope('tenant')
            ->where('token', $token)
            ->first();

        $this->userAlreadyExists = $invitation !== null
            && User::where('email', $invitation->email)->exists();
    }

    public function accept(): void
    {
        $rules = ['token' => ['required', 'string']];

        if (! $this->userAlreadyExists) {
            $rules['name'] = ['required', 'string', 'max:255'];
            $rules['password'] = ['required', 'string', 'min:8'];
        }

        $this->validate($rules);

        try {
            $user = app(InstructorInvitationService::class)->accept($this->token, [
                'name' => $this->name,
                'password' => $this->password,
            ]);
        } catch (ValidationException $e) {
            $this->addError('token', $e->validator->errors()->first('token'));

            return;
        }

        Auth::login($user);
        session()->regenerate();

        $this->accepted = true;
        $this->redirect("http://{$user->tenant->subdomain}.lms.test/teach");
    }

    public function render(): View
    {
        return view('livewire.instructor-invitation-accept');
    }
}
