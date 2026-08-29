<?php

use App\Domains\Api\Models\WebhookDelivery;
use App\Domains\Api\Models\WebhookEndpoint;
use App\Domains\Api\Services\ApiKeyService;
use App\Domains\Api\Services\WebhookDispatcher;
use App\Domains\AI\Livewire\DoubtChat;
use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\CourseCsvImporter;
use App\Domains\Tenancy\Models\FeatureFlag;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * S21 B15 — three real stories chained end to end, same posture every
 * prior slice's own final block established (S20LifecycleE2ETest, S11
 * B16). Each scenario drives real services, not a shortcut flip of a
 * status column.
 */
it('mints an API key, registers a webhook endpoint, and delivers an HMAC-signed webhook when an event fires', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $key = app(ApiKeyService::class)->issue($tenant, 'Zapier');

    $this->withHeader('Authorization', "Bearer {$key['plainKey']}")
        ->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson(['ok' => true, 'tenant' => 'physics']);

    $endpoint = WebhookEndpoint::create(['url' => 'https://institute.example.com/hook', 'secret' => 'topsecret']);

    Http::fake(['institute.example.com/*' => Http::response(['ok' => true], 200)]);

    app(WebhookDispatcher::class)->dispatch($tenant, 'payment.captured', ['enrolment_id' => 1]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->header('X-Webhook-Signature')[0] === hash_hmac('sha256', $request->body(), 'topsecret');
    });

    $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->sole();
    expect($delivery->status)->toBe('delivered');
});

it('gates a feature per tenant: enabled tenant reaches it, an unflagged tenant stays blocked', function () {
    $this->seed(RoleSeeder::class);

    $tenantA = Tenant::factory()->create(['subdomain' => 'physics']);
    $tenantB = Tenant::factory()->create(['subdomain' => 'chemistry']);

    app(CurrentTenant::class)->set($tenantA);
    FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => true]);
    $courseA = Course::create(['title' => 'Physics', 'slug' => 'physics-101', 'status' => 'published']);
    $sectionA = CourseSection::create(['course_id' => $courseA->id, 'title' => 'S', 'position' => 0]);
    $lessonA = Lesson::create(['course_section_id' => $sectionA->id, 'title' => 'L', 'type' => 'text', 'position' => 0, 'text_body' => 'x']);
    $studentA = User::factory()->create(['tenant_id' => $tenantA->id]);
    $studentA->assignRole('student');

    $this->actingAs($studentA);
    Livewire::test(DoubtChat::class, ['lesson' => $lessonA])
        ->set('question', 'What is velocity?')
        ->call('ask');

    expect(\App\Domains\AI\Models\DoubtChatMessage::where('lesson_id', $lessonA->id)->count())->toBe(1);

    // Unflagged tenant — same feature, no flag row at all.
    app(CurrentTenant::class)->set($tenantB);
    $courseB = Course::create(['title' => 'Chemistry', 'slug' => 'chem-101', 'status' => 'published']);
    $sectionB = CourseSection::create(['course_id' => $courseB->id, 'title' => 'S', 'position' => 0]);
    $lessonB = Lesson::create(['course_section_id' => $sectionB->id, 'title' => 'L', 'type' => 'text', 'position' => 0, 'text_body' => 'x']);
    $studentB = User::factory()->create(['tenant_id' => $tenantB->id]);
    $studentB->assignRole('student');

    $this->actingAs($studentB);
    Livewire::test(DoubtChat::class, ['lesson' => $lessonB])
        ->set('question', 'What is velocity?')
        ->call('ask');

    expect(\App\Domains\AI\Models\DoubtChatMessage::where('lesson_id', $lessonB->id)->count())->toBe(0);
});

it('dry-runs a course CSV import with zero writes, then a real run imports correctly', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $uploader = User::factory()->create(['tenant_id' => $tenant->id]);

    $csv = "title,slug,category\nPhysics 101,physics-101,Science\n,no-title,\n";

    $dryRun = app(CourseCsvImporter::class)->import($csv, $uploader, true);

    expect($dryRun->status)->toBe('dry_run')
        ->and($dryRun->error_rows)->toBe(1)
        ->and(Course::count())->toBe(0)
        ->and(Category::count())->toBe(0);

    $realRun = app(CourseCsvImporter::class)->import($csv, $uploader, false);

    expect($realRun->status)->toBe('completed')
        ->and($realRun->error_rows)->toBe(1)
        ->and(Course::where('slug', 'physics-101')->exists())->toBeTrue()
        ->and(Category::where('slug', 'science')->exists())->toBeTrue();
});
