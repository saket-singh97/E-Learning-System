<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeWhatsAppDriver;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('dispatches through CommsProvider when the notifiable has a phone number', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+919876543210']);

    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return [WhatsAppChannel::class];
        }

        public function toWhatsApp(object $notifiable): WhatsAppMessage
        {
            return new WhatsAppMessage('absence_alert', ['student_name' => 'Riya']);
        }
    };

    $channel = new WhatsAppChannel(new FakeWhatsAppDriver);
    $result = $channel->send($user, $notification);

    expect($result)->toHaveKeys(['message_id', 'status'])
        ->and($result['status'])->toBe('sent');
});

it('skips sending, without calling toWhatsApp, when the notifiable has no phone number', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => null]);

    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return [WhatsAppChannel::class];
        }

        public function toWhatsApp(object $notifiable): WhatsAppMessage
        {
            throw new RuntimeException('toWhatsApp should not be called when there is no phone to send to.');
        }
    };

    $channel = new WhatsAppChannel(new FakeWhatsAppDriver);
    $result = $channel->send($user, $notification);

    expect($result)->toBeNull();
});
