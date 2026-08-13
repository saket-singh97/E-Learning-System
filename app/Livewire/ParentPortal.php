<?php

namespace App\Livewire;

use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Comms\Services\StudentMetrics;
use App\Domains\Tenancy\Services\TenantAuthenticator;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Login/session shape mirrors StudentPortal exactly (same
 * TenantAuthenticator call, same session regenerate/invalidate). The
 * linked-student data (attendance/coverage/quiz/fee) is read-only, via
 * StudentMetrics — this component never mutates anything beyond its own
 * auth state.
 */
class ParentPortal extends Component
{
    public string $email = '';

    public string $password = '';

    public ?int $selectedStudentId = null;

    /**
     * Same two-schema-toggle shape as StudentPortal — see its own docblock.
     */
    public ?int $twoFactorUserId = null;

    public string $twoFactorCode = '';

    /**
     * SESSION_DOMAIN is a shared parent domain (CLAUDE.md), so a session
     * cookie from tenant A's parent login stays valid on tenant B's
     * subdomain — the same gap EnsureRole/DownloadInvoiceController close
     * by hand for their own surfaces (S7 B7). StudentPortal doesn't have
     * this check yet (pre-existing gap, out of this block's scope, flagged
     * in HANDOVER's "Avoid" list instead of fixed silently here); it's
     * added on this new surface because B1's own plan note requires it.
     */
    public function mount(): void
    {
        if (Auth::check() && Auth::user()->tenant_id !== app(CurrentTenant::class)->id()) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            return;
        }

        $this->resumePendingTwoFactorChallenge();

        if (Auth::check()) {
            $this->selectedStudentId = $this->linkedStudents()->first()?->id;
        }
    }

    /**
     * Same hand-off StudentPortal's own mount() documents — see its
     * docblock for why this exists (S9 B2/B14).
     */
    protected function resumePendingTwoFactorChallenge(): void
    {
        $pendingUserId = session()->pull('oauth.pending_two_factor_user_id');

        if ($pendingUserId === null) {
            return;
        }

        $user = User::find($pendingUserId);

        if ($user !== null && $user->tenant_id === app(CurrentTenant::class)->id()) {
            $this->twoFactorUserId = $user->id;
        }
    }

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        try {
            $user = app(TenantAuthenticator::class)->validate(
                email: $this->email,
                password: $this->password,
                tenantId: app(CurrentTenant::class)->id(),
                ip: request()->ip(),
                emailFieldKey: 'email',
            );
        } catch (ValidationException $e) {
            $this->addError('email', $e->validator->errors()->first('email'));

            return;
        }

        if ($user->hasTwoFactorEnabled()) {
            $this->twoFactorUserId = $user->id;
            $this->reset('password');

            return;
        }

        Auth::login($user);

        session()->regenerate();

        $this->reset(['email', 'password']);
        $this->selectedStudentId = $this->linkedStudents()->first()?->id;
    }

    public function verifyTwoFactorCode(): void
    {
        $this->validate(['twoFactorCode' => ['required', 'string']]);

        $user = User::findOrFail($this->twoFactorUserId);

        try {
            app(TenantAuthenticator::class)->challengeTwoFactor($user, $this->twoFactorCode, 'twoFactorCode');
        } catch (ValidationException $e) {
            $this->addError('twoFactorCode', $e->validator->errors()->first('twoFactorCode'));

            return;
        }

        Auth::login($user);

        session()->regenerate();

        $this->reset(['email', 'password', 'twoFactorUserId', 'twoFactorCode']);
        $this->selectedStudentId = $this->linkedStudents()->first()?->id;
    }

    public function logout(): void
    {
        Auth::logout();

        session()->invalidate();
        session()->regenerateToken();
    }

    /**
     * No explicit ownership check needed beyond this: linkedStudents() is
     * already scoped to the authenticated parent's own ParentGuardianLink
     * rows, so passing another parent's (or a random) student id here just
     * fails to match anything in render()'s firstWhere() — it can't select
     * data this parent isn't linked to.
     */
    public function selectStudent(int $studentId): void
    {
        $this->selectedStudentId = $studentId;
    }

    /**
     * @return Collection<int, User>
     */
    protected function linkedStudents(): Collection
    {
        return ParentGuardianLink::where('parent_user_id', Auth::id())
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter()
            ->values();
    }

    public function render(): View
    {
        $linkedStudents = Auth::check() ? $this->linkedStudents() : collect();
        $selectedStudent = $linkedStudents->firstWhere('id', $this->selectedStudentId);

        return view('livewire.parent-portal', [
            'linkedStudents' => $linkedStudents,
            'selectedStudent' => $selectedStudent,
            'metrics' => $selectedStudent !== null ? app(StudentMetrics::class)->summarize($selectedStudent) : null,
        ]);
    }
}
