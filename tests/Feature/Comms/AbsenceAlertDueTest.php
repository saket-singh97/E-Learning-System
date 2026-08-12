<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\AbsenceAlertDue;
use App\Notifications\Channels\WhatsAppChannel;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Riya']);
    $this->parent = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('sends over whatsapp and database', function () {
    $notification = new AbsenceAlertDue($this->student);

    expect($notification->via($this->parent))->toBe([WhatsAppChannel::class, 'database']);
});

it('builds a WhatsApp template message naming the absent student', function () {
    $notification = new AbsenceAlertDue($this->student);

    $message = $notification->toWhatsApp($this->parent);

    expect($message->template)->toBe('absence_alert')
        ->and($message->params['student_name'])->toBe('Riya')
        ->and($message->params['date'])->toBe(now()->toDateString());
});

it('stores the student user id in the database payload, for the dedupe check to key on', function () {
    $notification = new AbsenceAlertDue($this->student);

    $data = $notification->toArray($this->parent);

    expect($data['student_user_id'])->toBe($this->student->id)
        ->and($data['student_name'])->toBe('Riya');
});
