<?php

use App\Domains\Comms\Filament\Resources\Surveys\Pages\CreateSurvey;
use App\Domains\Comms\Filament\Resources\Surveys\Pages\EditSurvey;
use App\Domains\Comms\Filament\Resources\Surveys\Pages\ListSurveys;
use App\Domains\Comms\Filament\Resources\Surveys\SurveyResource;
use App\Domains\Comms\Models\Survey;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->actingAs($this->admin)
        ->get(SurveyResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('creates a survey with one question of each type, authored by the current admin', function () {
    Livewire::test(CreateSurvey::class)
        ->fillForm([
            'title' => 'Batch feedback',
            'description' => 'How is the batch going?',
            'status' => 'draft',
            'questions' => [
                ['prompt' => 'Any comments?', 'type' => 'text'],
                ['prompt' => 'Favourite topic?', 'type' => 'single_choice', 'options' => ['choices' => ['Mechanics', 'Optics']]],
                ['prompt' => 'Rate the pace', 'type' => 'rating', 'options' => ['max' => 5]],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $survey = Survey::where('title', 'Batch feedback')->firstOrFail();
    expect($survey->created_by_user_id)->toBe($this->admin->id);
    expect($survey->status)->toBe('draft');
    expect($survey->questions)->toHaveCount(3);

    $textQuestion = $survey->questions->firstWhere('type', 'text');
    expect($textQuestion->options)->toBeNull();

    $choiceQuestion = $survey->questions->firstWhere('type', 'single_choice');
    expect($choiceQuestion->options)->toBe(['choices' => ['Mechanics', 'Optics']]);

    $ratingQuestion = $survey->questions->firstWhere('type', 'rating');
    expect($ratingQuestion->options)->toBe(['max' => 5]);
});

it('publishes a survey by editing its status', function () {
    $survey = Survey::create(['title' => 'Draft one', 'status' => 'draft', 'created_by_user_id' => $this->admin->id]);

    Livewire::test(EditSurvey::class, ['record' => $survey->id])
        ->fillForm(['status' => 'published'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($survey->fresh()->status)->toBe('published');
    expect($survey->fresh()->isPublished())->toBeTrue();
});

it('lists surveys for the current tenant only', function () {
    $mine = Survey::create(['title' => 'Mine', 'created_by_user_id' => $this->admin->id]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $theirs = Survey::create(['title' => 'Theirs', 'created_by_user_id' => $otherAdmin->id]);
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(ListSurveys::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('is not reachable from the Teach panel', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/teach/surveys')
        ->assertNotFound();
});
