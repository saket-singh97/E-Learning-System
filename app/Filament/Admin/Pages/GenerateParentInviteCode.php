<?php

namespace App\Filament\Admin\Pages;

use App\Domains\Comms\Services\ParentInviteCodeService;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Neither of the two homes this block's own plan note offered turned out
 * to fit: ParentGuardianResource (S8 B1) only ever lists parent-role
 * users, and "the student's own admin record" doesn't exist — there is no
 * Students Resource anywhere in this app (confirmed by listing every
 * Filament Resource in app/Domains, not assumed). Same "single custom page,
 * one select, one action" shape this session already used three times
 * (ImpersonateUser, CustomDomainSettings) rather than force-fitting either
 * option or building a full Students Resource this block doesn't need.
 */
class GenerateParentInviteCode extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Parent Invite Codes';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?string $generatedCode = null;

    public ?string $generatedExpiresAt = null;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('student_user_id')
                    ->label('Student')
                    ->options(fn (): array => User::role('student')
                        ->where('tenant_id', app(CurrentTenant::class)->id())
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Placeholder::make('generated_code')
                    ->label('Invite code')
                    ->content(fn (): string => $this->generatedCode !== null
                        ? "{$this->generatedCode} — expires {$this->generatedExpiresAt}"
                        : '')
                    ->visible(fn (): bool => $this->generatedCode !== null),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate invite code')
                ->action(function (): void {
                    $student = User::findOrFail($this->form->getState()['student_user_id']);

                    $invite = app(ParentInviteCodeService::class)->generate($student, Auth::user());

                    $this->generatedCode = $invite->code;
                    $this->generatedExpiresAt = $invite->expires_at->toDayDateTimeString();

                    Notification::make()
                        ->title("Invite code generated: {$invite->code}")
                        ->body("Share this with {$student->name}'s parent — it expires {$this->generatedExpiresAt}.")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
        ]);
    }
}
