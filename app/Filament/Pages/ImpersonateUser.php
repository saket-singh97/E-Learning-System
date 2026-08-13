<?php

namespace App\Filament\Pages;

use App\Domains\Tenancy\Services\ImpersonationService;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * The Super panel's only user-facing page beyond Dashboard — there is no
 * Tenant/User Resource anywhere in this panel yet (nothing at all was
 * registered under app/Domains/Tenancy/Filament/Resources before this
 * block), so a single search-and-go select is the whole surface rather
 * than a table-with-row-action a Resource would give for free. Loads every
 * non-super_admin user into one dropdown — fine at this app's current
 * scale (no demo or design-doc note asks for more), a searchable
 * lazy-loaded options callback is the fast-follow once the user count
 * actually makes eager-loading slow.
 */
class ImpersonateUser extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?string $navigationLabel = 'Impersonate User';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('target_user_id')
                    ->label('User to impersonate')
                    ->options(fn (): array => User::query()
                        ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
                        ->with('tenant')
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [
                            $user->id => "{$user->name} ({$user->email}) — ".($user->tenant?->name ?? 'no tenant'),
                        ])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('impersonate')
                ->label('Start impersonating')
                ->action(function (): void {
                    $target = User::findOrFail($this->form->getState()['target_user_id']);

                    app(ImpersonationService::class)->start(Auth::user(), $target);

                    $this->redirect($this->redirectUrlFor($target));
                }),
        ];
    }

    protected function redirectUrlFor(User $target): string
    {
        $subdomain = $target->tenant?->subdomain;

        return match (true) {
            $target->hasRole('institute_admin') => "http://{$subdomain}.lms.test/admin",
            $target->hasRole('instructor') => "http://{$subdomain}.lms.test/teach",
            $target->hasRole('parent') => "http://{$subdomain}.lms.test/parent/dashboard",
            default => "http://{$subdomain}.lms.test/dashboard",
        };
    }
}
