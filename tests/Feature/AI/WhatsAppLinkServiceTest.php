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

// S24 B13 review pass — verifyCode() used to Cache::pull() (consume) the
// code before checking the phone, so a student retrying with the correct
// number after a typo had to go generate a brand new code for no reason.
it('keeps a code valid for retry when verification fails only on the phone-already-linked check', function () {
    $service = app(WhatsAppLinkService::class);
    $code = $service->generateCode($this->student);

    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $service->verifyCode($service->generateCode($otherStudent), '+919876500010');

    expect(fn () => $service->verifyCode($code, '+919876500010'))
        ->toThrow(ValidationException::class);

    // Same code, different (correct) number — must still succeed.
    $link = $service->verifyCode($code, '+919876500011');
    expect($link->user_id)->toBe($this->student->id);
});
