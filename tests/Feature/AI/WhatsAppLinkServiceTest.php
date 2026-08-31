<?php

use App\Domains\AI\Models\WhatsAppLink;
use App\Domains\AI\Services\WhatsAppLinkService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('verifies a generated code and creates a verified whatsapp_links row', function () {
    $code = app(WhatsAppLinkService::class)->generateCode($this->student);

    $link = app(WhatsAppLinkService::class)->verifyCode($code, '+919876500001');

    expect($link)->toBeInstanceOf(WhatsAppLink::class)
        ->and($link->tenant_id)->toBe($this->tenant->id)
        ->and($link->user_id)->toBe($this->student->id)
        ->and($link->phone_e164)->toBe('+919876500001')
        ->and($link->verified_at)->not->toBeNull();
});

it('rejects an invalid or expired code', function () {
    expect(fn () => app(WhatsAppLinkService::class)->verifyCode('BADCODE1', '+919876500002'))
        ->toThrow(ValidationException::class);
});

it('rejects linking an already-linked phone number with a clean error, not a 500', function () {
    $service = app(WhatsAppLinkService::class);
    $firstCode = $service->generateCode($this->student);
    $service->verifyCode($firstCode, '+919876500003');

    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $secondCode = $service->generateCode($otherStudent);

    expect(fn () => $service->verifyCode($secondCode, '+919876500003'))
        ->toThrow(ValidationException::class);

    expect(WhatsAppLink::where('phone_e164', '+919876500003')->count())->toBe(1);
});
