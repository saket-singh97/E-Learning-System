<?php

namespace App\Domains\Tenancy\Filament\Resources\InstructorInvitations\Pages;

use App\Domains\Batches\Models\Batch;
use App\Domains\Operations\Jobs\InviteInstructorsFromEmailList;
use App\Domains\Tenancy\Filament\Resources\InstructorInvitations\InstructorInvitationResource;
use App\Support\Tenancy\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListInstructorInvitations extends ListRecords
{
    protected static string $resource = InstructorInvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            // F10 pt.2 — thin ShouldQueue wrapper around the single-invite
            // path above, once per email (design decision 8), same "add
            // a header action alongside the existing single-row one"
            // shape B11's own "Bulk enrol" action already established.
            Action::make('bulkInvite')
                ->label('Bulk invite')
                ->schema([
                    Textarea::make('emails')
                        ->label('Instructor emails (one per line)')
                        ->required(),
                    Select::make('batch_ids')
                        ->label('Assign batches')
                        ->multiple()
                        ->options(fn (): array => Batch::query()->pluck('title', 'id')->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $emails = collect(explode("\n", (string) $data['emails']))
                        ->map(fn (string $email): string => strtolower(trim($email)))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    InviteInstructorsFromEmailList::dispatch(
                        app(CurrentTenant::class)->id(),
                        $emails,
                        array_map('intval', $data['batch_ids']),
                        auth()->id(),
                    );

                    Notification::make()->title('Bulk invitations queued')->success()->send();
                }),
        ];
    }
}
