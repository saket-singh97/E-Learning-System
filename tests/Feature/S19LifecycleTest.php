<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Events\ForumPostCreated;
use App\Domains\Comms\Events\HelpDeskTicketReplyCreated;
use App\Domains\Comms\Filament\Resources\HelpDeskTickets\HelpDeskTicketResource;
use App\Domains\Comms\Filament\Resources\HelpDeskTickets\Pages\ListHelpDeskTickets;
use App\Domains\Comms\Jobs\ExportSurveyResponsesCsv;
use App\Domains\Comms\Livewire\GeneralForum;
use App\Domains\Comms\Livewire\HelpDeskTicketForm;
use App\Domains\Comms\Livewire\SurveyRunner;
use App\Domains\Comms\Models\ForumThread;
use App\Domains\Comms\Models\HelpDeskTicket;
use App\Domains\Comms\Models\Survey;
use App\Domains\Comms\Models\SurveyQuestion;
use App\Domains\Comms\Services\SurveyResponseAggregator;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * S19 B16 — full slice sign-off: three chained lifecycles spanning multiple
 * blocks' own write paths in one run each, not re-testing any single unit
 * already covered by that block's own test file (B7-B9, B4-B6, B2-B3).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);
});

it('walks a help-desk ticket from raise to staff reply (real-time) to resolve', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');
    $requester = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $requester->assignRole('student');

    Livewire::actingAs($requester)
        ->test(HelpDeskTicketForm::class)
        ->set('subject', 'Cannot access lesson')
        ->set('body', 'Video never loads.')
        ->set('priority', 'high')
        ->call('raiseTicket')
        ->assertHasNoErrors();

    $ticket = HelpDeskTicket::where('requester_id', $requester->id)->firstOrFail();
    expect($ticket->status)->toBe('open');

    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
    Event::fake([HelpDeskTicketReplyCreated::class]);

    $this->actingAs($admin)
        ->get(HelpDeskTicketResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::actingAs($admin)
        ->test(ListHelpDeskTickets::class)
        ->callTableAction('reply', $ticket, data: ['body' => 'Please clear your cache and retry.', 'is_internal_note' => false])
        ->assertHasNoTableActionErrors();

    Event::assertDispatched(HelpDeskTicketReplyCreated::class, fn (HelpDeskTicketReplyCreated $event): bool => (string) $event->broadcastOn()[0] === 'private-help-desk-ticket.'.$ticket->id
        && $event->broadcastWith()['body'] === 'Please clear your cache and retry.');

    Livewire::actingAs($requester)
        ->test(HelpDeskTicketForm::class)
        ->call('openTicket', $ticket->id)
        ->assertSee('Please clear your cache and retry.');

    Livewire::actingAs($admin)
        ->test(ListHelpDeskTickets::class)
        ->callTableAction('resolve', $ticket)
        ->assertHasNoTableActionErrors();

    expect($ticket->fresh()->status)->toBe('resolved')
        ->and($ticket->fresh()->resolved_at)->not->toBeNull();
});

it('walks a survey from publish through two responses to aggregation and csv export', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $respondent1 = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Asha Kumar', 'email' => 'asha@example.com']);
    $respondent2 = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Ravi Shah', 'email' => 'ravi@example.com']);

    $survey = Survey::create(['title' => 'Batch feedback', 'status' => 'published', 'created_by_user_id' => $admin->id]);
    $ratingQuestion = SurveyQuestion::create(['survey_id' => $survey->id, 'prompt' => 'Rate the pace', 'type' => 'rating', 'options' => ['max' => 5], 'position' => 0]);
    $choiceQuestion = SurveyQuestion::create(['survey_id' => $survey->id, 'prompt' => 'Favourite topic?', 'type' => 'single_choice', 'options' => ['choices' => ['Mechanics', 'Optics']], 'position' => 1]);

    foreach ([[$respondent1, 4, 'Mechanics'], [$respondent2, 2, 'Mechanics']] as [$respondent, $rating, $choice]) {
        Livewire::actingAs($respondent)
            ->test(SurveyRunner::class)
            ->call('openSurvey', $survey->id)
            ->set("answers.{$ratingQuestion->id}", $rating)
            ->set("answers.{$choiceQuestion->id}", $choice)
            ->call('submit')
            ->assertHasNoErrors();
    }

    $aggregate = collect(app(SurveyResponseAggregator::class)->aggregateFor($survey));
    expect($aggregate->firstWhere('question.id', $ratingQuestion->id)['summary'])->toBe(['average' => 3.0, 'responses' => 2]);
    expect($aggregate->firstWhere('question.id', $choiceQuestion->id)['summary'])->toBe(['Mechanics' => 2, 'Optics' => 0]);

    ExportSurveyResponsesCsv::dispatchSync($survey->id, $this->tenant->id);
    $path = ExportSurveyResponsesCsv::pathFor($survey->id);
    $csv = Storage::disk('local')->get($path);

    expect($csv)->toContain('Asha Kumar')->toContain('Ravi Shah')->toContain('Mechanics');
    expect(substr_count($csv, "\n"))->toBe(3);

    Storage::disk('local')->delete($path);
});

it('lets two students from different batches create and reply to a general-forum thread, both pushed live', function () {
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101-'.uniqid(), 'status' => 'published']);
    $batch1 = Batch::create(['course_id' => $course->id, 'title' => 'Batch 1', 'slug' => 'batch-1-'.uniqid(), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'self_enrol_mode' => 'manual_only', 'status' => 'published']);
    $batch2 = Batch::create(['course_id' => $course->id, 'title' => 'Batch 2', 'slug' => 'batch-2-'.uniqid(), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'self_enrol_mode' => 'manual_only', 'status' => 'published']);

    $studentA = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $studentB = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Enrolment::create(['batch_id' => $batch1->id, 'user_id' => $studentA->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);
    Enrolment::create(['batch_id' => $batch2->id, 'user_id' => $studentB->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
    Event::fake([ForumPostCreated::class]);

    Livewire::actingAs($studentA)
        ->test(GeneralForum::class)
        ->set('newThreadTitle', 'Cross-batch question')
        ->set('newThreadBody', 'Anyone else stuck on assignment 2?')
        ->call('createThread');

    $thread = ForumThread::whereNull('batch_id')->firstOrFail();
    $rootPost = $thread->posts()->firstOrFail();

    Livewire::actingAs($studentB)
        ->test(GeneralForum::class)
        ->assertSee('Cross-batch question')
        ->call('openThread', $thread->id)
        ->set("replyBodies.{$rootPost->id}", 'Same issue here.')
        ->call('reply', $rootPost->id)
        ->assertSee('Same issue here.');

    Event::assertDispatchedTimes(ForumPostCreated::class, 2);
    Event::assertDispatched(ForumPostCreated::class, fn (ForumPostCreated $event): bool => (string) $event->broadcastOn()[0] === 'private-forum-thread.'.$thread->id
        && $event->broadcastWith()['body'] === 'Anyone else stuck on assignment 2?');
    Event::assertDispatched(ForumPostCreated::class, fn (ForumPostCreated $event): bool => (string) $event->broadcastOn()[0] === 'private-forum-thread.'.$thread->id
        && $event->broadcastWith()['body'] === 'Same issue here.');
});
