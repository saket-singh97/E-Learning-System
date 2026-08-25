<?php

use App\Domains\AI\Jobs\ProcessPhotoDoubtQuery;
use App\Domains\AI\Models\PhotoDoubtQuery;
use App\Domains\AI\Services\PhotoDoubtService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidFileTypeException;
use App\Exceptions\PhotoTooLargeException;
use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\Drivers\FakeAiDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

class FailingChatWithImageDriver extends FakeAiDriver
{
    public function chatWithImage(array $messages, string $imageDiskPath): ChatResult
    {
        throw new RuntimeException('vendor is down');
    }
}

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('creates a pending row and dispatches the processing job', function () {
    Bus::fake();

    $image = UploadedFile::fake()->image('question.jpg');

    $query = app(PhotoDoubtService::class)->submit($image, $this->student, null);

    expect($query)->toBeInstanceOf(PhotoDoubtQuery::class)
        ->and($query->status)->toBe('pending')
        ->and($query->user_id)->toBe($this->student->id)
        ->and($query->image_disk_path)->not->toBeNull();

    Storage::disk('local')->assertExists($query->image_disk_path);

    Bus::assertDispatched(ProcessPhotoDoubtQuery::class, fn (ProcessPhotoDoubtQuery $job): bool => $job->queryId === $query->id);
});

it('rejects a disallowed file extension', function () {
    $file = UploadedFile::fake()->create('question.pdf', 100);

    expect(fn () => app(PhotoDoubtService::class)->submit($file, $this->student, null))
        ->toThrow(InvalidFileTypeException::class);
});

it('rejects a photo over the size cap', function () {
    $file = UploadedFile::fake()->create('question.jpg', PhotoDoubtService::MAX_SIZE_KB + 1);

    expect(fn () => app(PhotoDoubtService::class)->submit($file, $this->student, null))
        ->toThrow(PhotoTooLargeException::class);
});

it('transitions pending to ready with a solution from the fake driver', function () {
    $image = UploadedFile::fake()->image('question.jpg');
    $query = app(PhotoDoubtService::class)->submit($image, $this->student, null);

    (new ProcessPhotoDoubtQuery($query->id, $query->tenant_id))->handle(app(AiProvider::class));

    $query->refresh();

    expect($query->status)->toBe('ready')
        ->and($query->solution)->not->toBeEmpty()
        ->and($query->token_cost)->toHaveKeys(['in', 'out', 'usd']);
});

it('transitions to failed instead of throwing when the driver fails', function () {
    app()->bind(AiProvider::class, FailingChatWithImageDriver::class);

    $image = UploadedFile::fake()->image('question.jpg');
    $query = app(PhotoDoubtService::class)->submit($image, $this->student, null);

    (new ProcessPhotoDoubtQuery($query->id, $query->tenant_id))->handle(app(AiProvider::class));

    expect($query->refresh()->status)->toBe('failed');
});
