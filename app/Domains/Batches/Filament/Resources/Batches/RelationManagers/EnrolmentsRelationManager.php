<?php

namespace App\Domains\Batches\Filament\Resources\Batches\RelationManagers;

use App\Domains\Batches\Jobs\BulkEnrolStudents;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Policies\EnrolmentApprovalPolicy;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Batches\Services\IdCardGenerator;
use App\Exceptions\EnrolmentApprovalDeniedException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EnrolmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrolments';

    public function form(Schema $schema): Schema
    {
        /** @var Batch $batch */
        $batch = $this->getOwnerRecord();

        return $schema->components([
            Select::make('user_id')
                ->label('Student')
                ->options(fn () => User::role('student')
                    ->where('tenant_id', $batch->tenant_id)
                    ->whereNotIn('id', $batch->enrolments()->pluck('user_id'))
                    ->pluck('name', 'id'))
                ->searchable()
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('user.name')->label('Student')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending_approval', 'pending_payment' => 'warning',
                        'waitlisted' => 'info',
                        'rejected', 'expired', 'cancelled' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('source')->badge(),
                TextColumn::make('requested_at')->dateTime(),
                TextColumn::make('decided_at')->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(Enrolment::STATUSES, Enrolment::STATUSES)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add student')
                    ->using(function (array $data): Enrolment {
                        $actor = $this->authorizedActor();
                        /** @var Batch $batch */
                        $batch = $this->getOwnerRecord();

                        return app(BatchEnrolmentService::class)->addManually(
                            $batch,
                            User::findOrFail($data['user_id']),
                            $actor,
                        );
                    }),
                Action::make('bulkEnrol')
                    ->label('Bulk enrol')
                    // F10 pt.1 — thin ShouldQueue wrapper around the exact
                    // same addManually() call above, once per email
                    // (design decision 7); existing students only, an
                    // email with no matching User is skipped, not created.
                    ->schema([
                        Textarea::make('emails')
                            ->label('Student emails (one per line)')
                            ->required()
                            ->helperText('Existing students in this tenant only.'),
                    ])
                    ->action(function (array $data): void {
                        $actor = $this->authorizedActor();
                        /** @var Batch $batch */
                        $batch = $this->getOwnerRecord();

                        $emails = collect(explode("\n", (string) $data['emails']))
                            ->map(fn (string $email): string => strtolower(trim($email)))
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();

                        BulkEnrolStudents::dispatch($batch->tenant_id, $batch->id, $emails, $actor->id);

                        Notification::make()->title('Bulk enrolment queued')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Enrolment $record): bool => $record->status === 'pending_approval')
                    ->action(fn (Enrolment $record) => app(BatchEnrolmentService::class)->approve(
                        $record,
                        $this->authorizedActor(),
                    )),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Enrolment $record): bool => $record->status === 'pending_approval')
                    ->action(fn (Enrolment $record) => app(BatchEnrolmentService::class)->reject(
                        $record,
                        $this->authorizedActor(),
                    )),
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Enrolment $record): bool => in_array($record->status, ['active', 'waitlisted', 'pending_approval'], true))
                    ->action(function (Enrolment $record) {
                        $this->authorizedActor();

                        app(BatchEnrolmentService::class)->cancel($record);
                    }),
                // S5 — on demand only (design decision 10), never
                // auto-issued: a physical/printable artifact an institute
                // deliberately triggers, not something every Enrolment
                // edit should keep regenerating.
                Action::make('issueIdCard')
                    ->label('Issue ID card')
                    ->requiresConfirmation()
                    ->visible(fn (Enrolment $record): bool => $record->status === 'active' && ! $record->idCard()->exists())
                    ->action(function (Enrolment $record): void {
                        $this->authorizedActor();

                        app(IdCardGenerator::class)->generate($record);

                        Notification::make()->title('ID card issued')->success()->send();
                    }),
                Action::make('downloadIdCard')
                    ->label('Download ID card')
                    ->visible(fn (Enrolment $record): bool => $record->idCard()->exists())
                    ->url(fn (Enrolment $record): string => route('id-card.download', [
                        'enrolment' => $record->id,
                        'subdomain' => app(CurrentTenant::class)->get()->subdomain,
                    ]))
                    ->openUrlInNewTab(),
            ]);
    }

    /**
     * EnrolmentApprovalPolicy denies before BatchEnrolmentService::approve/
     * reject() runs — checked here rather than inside the service, since
     * this is an authorization question, not a state-transition one (design
     * doc §3.4).
     */
    protected function authorizedActor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();
        /** @var Batch $batch */
        $batch = $this->getOwnerRecord();

        if (! app(EnrolmentApprovalPolicy::class)->canDecide($actor, $batch)) {
            throw EnrolmentApprovalDeniedException::forActor($actor->id, $batch->id);
        }

        return $actor;
    }
}
