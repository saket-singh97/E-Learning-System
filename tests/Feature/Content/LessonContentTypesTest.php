<?php

use App\Domains\Billing\Services\StorageQuota;
use App\Domains\Content\Filament\Resources\Lessons\Pages\EditLesson;
use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\StorageLimitExceededException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function contentTypeFixture(array $lessonAttrs, ?string $subdomain = 'physics'): array
{
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create($subdomain !== null ? ['subdomain' => $subdomain] : []);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(array_merge([
        'course_section_id' => $section->id,
        'title' => 'Reading material',
        'position' => 0,
    ], $lessonAttrs));

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    return [$tenant, $lesson->fresh(), $student];
}

beforeEach(fn () => Storage::fake('local'));

it('renders sanitized text lesson content on the watch page', function () {
    [, $lesson, $student] = contentTypeFixture([
        'type' => 'text',
        'text_body' => '<p>Hello</p><script>alert(1)</script>',
    ]);

    expect($lesson->text_body)->toBe('<p>Hello</p>alert(1)');

    $this->actingAs($student)
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertOk()
        ->assertSee('Hello', false)
        ->assertDontSee('<script>', false);
});

it('renders an embed lesson as an iframe pointing at its allow-listed url', function () {
    [, $lesson, $student] = contentTypeFixture([
        'type' => 'embed',
        'embed_url' => 'https://www.youtube.com/embed/abc123',
    ]);

    $this->actingAs($student)
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertOk()
        ->assertSee('https://www.youtube.com/embed/abc123', false);
});

it('rejects a non-allow-listed embed url on the lesson form', function () {
    [$tenant, $lesson] = contentTypeFixture(['type' => 'embed']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$lesson->id}/edit")
        ->assertOk();

    Livewire::test(EditLesson::class, ['record' => $lesson->id])
        ->fillForm([
            'title' => 'Reading material',
            'type' => 'embed',
            'embed_url' => 'https://evil.example.com/whatever',
        ])
        ->call('save')
        ->assertHasFormErrors(['embed_url']);
});

it('accepts an allow-listed embed url on the lesson form', function () {
    [$tenant, $lesson] = contentTypeFixture(['type' => 'embed']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$lesson->id}/edit")
        ->assertOk();

    Livewire::test(EditLesson::class, ['record' => $lesson->id])
        ->fillForm([
            'title' => 'Reading material',
            'type' => 'embed',
            'embed_url' => 'https://player.vimeo.com/video/1',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lesson->fresh()->embed_url)->toBe('https://player.vimeo.com/video/1');
});

it('attaches an uploaded pdf and serves it through the signed pdf download route', function () {
    [$tenant, $lesson] = contentTypeFixture(['type' => 'pdf']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$lesson->id}/edit")
        ->assertOk();

    Livewire::test(EditLesson::class, ['record' => $lesson->id])
        ->fillForm([
            'title' => 'Reading material',
            'type' => 'pdf',
            'pdf_upload' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $lesson->refresh();
    expect($lesson->pdf_disk_path)->toBe("pdfs/{$tenant->id}/{$lesson->id}.pdf");
    Storage::disk('local')->assertExists($lesson->pdf_disk_path);

    $signedUrl = URL::temporarySignedRoute('watch.pdf', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->id,
    ]);

    $this->actingAs($student)->get($signedUrl)->assertOk();
});

it('blocks the pdf download once the tenant is over its daily bandwidth quota (S9 B12)', function () {
    [$tenant, $lesson] = contentTypeFixture(['type' => 'pdf']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $this->actingAs($instructor)
        ->get("http://physics.lms.test/teach/lessons/{$lesson->id}/edit")
        ->assertOk();

    Livewire::test(EditLesson::class, ['record' => $lesson->id])
        ->fillForm([
            'title' => 'Reading material',
            'type' => 'pdf',
            'pdf_upload' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // UploadedFile::fake()->create($name, $kilobytes) reports that size via
    // getSize() metadata but writes no real bytes into Storage::fake()'s
    // disk — filesize() reads back 0, which would never exceed any quota
    // regardless of how over-cap the tenant already is. Overwritten with
    // real content here so the quota check has an actual byte count to
    // compare against, same reasoning meteredLesson() (BandwidthMeteringTest)
    // puts a real string directly rather than relying on a fake upload's
    // own (zero) bytes.
    Storage::disk('local')->put($lesson->fresh()->pdf_disk_path, str_repeat('x', 1000));

    app(CurrentTenant::class)->set($tenant);
    BandwidthUsageDaily::create([
        'date' => now()->toDateString(),
        'bytes_served' => 999_999_999_999,
    ]);

    $signedUrl = URL::temporarySignedRoute('watch.pdf', now()->addMinutes(10), [
        'subdomain' => 'physics',
        'lesson' => $lesson->fresh()->id,
    ]);

    $this->actingAs($student)->get($signedUrl)->assertStatus(409);
});

it('404s a pdf lesson with no file attached yet', function () {
    [, $lesson, $student] = contentTypeFixture(['type' => 'pdf']);

    $this->actingAs($student)
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertNotFound();
});

it('reports isPlayable per content type', function () {
    $access = app(LessonAccess::class);

    [, $pdfLesson] = contentTypeFixture(['type' => 'pdf'], null);
    expect($access->isPlayable($pdfLesson))->toBeFalse();
    $pdfLesson->forceFill(['pdf_disk_path' => 'pdfs/1/1.pdf'])->save();
    expect($access->isPlayable($pdfLesson->fresh()))->toBeTrue();

    [, $textLesson] = contentTypeFixture(['type' => 'text'], null);
    expect($access->isPlayable($textLesson))->toBeFalse();
    $textLesson->update(['text_body' => 'Hello']);
    expect($access->isPlayable($textLesson->fresh()))->toBeTrue();

    [, $embedLesson] = contentTypeFixture(['type' => 'embed'], null);
    expect($access->isPlayable($embedLesson))->toBeFalse();
    $embedLesson->update(['embed_url' => 'https://www.youtube.com/embed/abc']);
    expect($access->isPlayable($embedLesson->fresh()))->toBeTrue();
});

it('tracks an attached pdf against the tenant storage counter (S11 B4)', function () {
    [$tenant, $lesson] = contentTypeFixture(['type' => 'pdf']);

    // UploadedFile::fake() writes no real bytes into Storage::fake() (this
    // file's own later test documents the same gotcha) — writing directly
    // to a temp path gives attachUploadedPdf() a real byte count to read,
    // matching how a real FileUpload field's dehydrated temp path already
    // has genuine content sitting on disk in production.
    $tempPath = 'livewire-tmp/notes.pdf';
    Storage::disk('local')->put($tempPath, str_repeat('x', 2000));

    $lesson->attachUploadedPdf($tempPath);

    expect(app(StorageQuota::class)->usedNow($tenant))->toBe(2000);
});

it('nets out a same-extension pdf re-upload to only the size delta', function () {
    [$tenant, $lesson] = contentTypeFixture(['type' => 'pdf']);

    Storage::disk('local')->put('livewire-tmp/first.pdf', str_repeat('x', 2000));
    $lesson->attachUploadedPdf('livewire-tmp/first.pdf');

    Storage::disk('local')->put('livewire-tmp/second.pdf', str_repeat('x', 3000));
    $lesson->fresh()->attachUploadedPdf('livewire-tmp/second.pdf');

    expect(app(StorageQuota::class)->usedNow($tenant))->toBe(3000);
});

it('blocks attaching a pdf once the tenant storage limit is reached', function () {
    [$tenant, $lesson] = contentTypeFixture(['type' => 'pdf']);
    app(StorageQuota::class)->adjust($tenant, (5 * 1024 * 1024 * 1024) - 500);

    Storage::disk('local')->put('livewire-tmp/notes.pdf', str_repeat('x', 2000));

    expect(fn () => $lesson->attachUploadedPdf('livewire-tmp/notes.pdf'))
        ->toThrow(StorageLimitExceededException::class);

    expect($lesson->fresh()->pdf_disk_path)->toBeNull();
});

it('releases storage when a pdf lesson is force-deleted', function () {
    [$tenant, $lesson] = contentTypeFixture(['type' => 'pdf']);

    Storage::disk('local')->put('livewire-tmp/notes.pdf', str_repeat('x', 2000));
    $lesson->attachUploadedPdf('livewire-tmp/notes.pdf');
    expect(app(StorageQuota::class)->usedNow($tenant))->toBe(2000);

    $lesson->fresh()->forceDelete();

    expect(app(StorageQuota::class)->usedNow($tenant))->toBe(0);
});

it('marks the video player with download-protection attributes', function () {
    [, $lesson, $student] = contentTypeFixture([
        'type' => 'video',
        'video_status' => 'ready',
    ]);
    $lesson->update(['video_disk_path' => "videos/{$lesson->tenant_id}/{$lesson->id}.mp4"]);
    Storage::disk('local')->put($lesson->video_disk_path, 'fake video bytes');

    $this->actingAs($student)
        ->get("http://physics.lms.test/lessons/{$lesson->id}")
        ->assertOk()
        ->assertSee('controlsList="nodownload"', false)
        ->assertSee('oncontextmenu="return false"', false);
});
