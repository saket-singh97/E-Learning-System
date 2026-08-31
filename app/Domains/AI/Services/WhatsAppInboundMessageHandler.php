<?php

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\WhatsAppLink;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Integrations\CommsProvider;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Validation\ValidationException;

/**
 * Everything that happens once an inbound WhatsApp payload's signature is
 * confirmed genuine — same "controller stays thin, the service owns
 * verification and everything after it" shape PaymentReconciler already
 * established for the Razorpay webhook.
 *
 * Only `type: text` messages are handled (design §2.2/§4 decision 3-4 —
 * voice notes and photos are B2/B3, branching onto this same handler
 * later). Anything else is a silent no-op 200, not an error, since the
 * vendor's own delivery retries would otherwise hammer an endpoint that
 * simply doesn't support that message type yet.
 */
class WhatsAppInboundMessageHandler
{
    private const UNLINKED_REPLY = "This number isn't linked to a student account yet. "
        .'Open your student portal and generate a linking code, then text it here as "LINK <code>".';

    private const NO_MATCH_REPLY = "Couldn't find a lesson matching that question — try rephrasing, "
        .'or ask it from inside the lesson player instead.';

    public function __construct(
        private readonly WhatsAppLinkService $links,
        private readonly SemanticSearchService $search,
        private readonly DoubtChatService $doubtChat,
        private readonly CommsProvider $comms,
    ) {}

    public function handle(string $payload, string $signature): void
    {
        $this->verifySignature($payload, $signature);

        $data = json_decode($payload, true) ?? [];

        if (data_get($data, 'type') !== 'text') {
            return;
        }

        $from = (string) data_get($data, 'from', '');
        $text = trim((string) data_get($data, 'text.body', ''));

        if ($from === '' || $text === '') {
            return;
        }

        if (preg_match('/^link\s+(\S+)$/i', $text, $matches) === 1) {
            $this->handleLinkingAttempt($matches[1], $from);

            return;
        }

        $this->handleDoubt($from, $text);
    }

    private function verifySignature(string $payload, string $signature): void
    {
        $secret = config('services.aisensy.webhook_secret');

        if (blank($secret) || ! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            throw InvalidWebhookSignatureException::make();
        }
    }

    private function handleLinkingAttempt(string $code, string $phone): void
    {
        try {
            $this->links->verifyCode($code, $phone);
            $this->comms->sendSessionMessage($phone, 'WhatsApp linked! You can ask doubts here anytime.');
        } catch (ValidationException $e) {
            $this->comms->sendSessionMessage($phone, $e->validator->errors()->first());
        }
    }

    private function handleDoubt(string $phone, string $question): void
    {
        $link = WhatsAppLink::withoutGlobalScope('tenant')->where('phone_e164', $phone)->first();

        if ($link === null) {
            $this->comms->sendSessionMessage($phone, self::UNLINKED_REPLY);

            return;
        }

        // No tenant subdomain to resolve from (this route is platform-wide)
        // — rebind to the linked student's own tenant for this call only,
        // same restore-after shape WhatsAppLinkService::verifyCode() and
        // ParentInviteCodeService::redeem() already use.
        $currentTenant = app(CurrentTenant::class);
        $previousTenant = $currentTenant->isBound() ? $currentTenant->get() : null;
        $currentTenant->set(Tenant::findOrFail($link->tenant_id));

        try {
            $viewer = User::findOrFail($link->user_id);
            $chunks = $this->search->search($question, $viewer, $link->tenant_id);

            if ($chunks->isEmpty()) {
                $this->comms->sendSessionMessage($phone, self::NO_MATCH_REPLY);

                return;
            }

            $lesson = $chunks->first()->lessonArtifact->lesson;

            // ask() itself stays unchanged (design §4 decision 2) — channel/
            // source_phone are set as a follow-up update, not a new ask()
            // param.
            $message = $this->doubtChat->ask($lesson, $viewer, $question);
            $message->update(['channel' => 'whatsapp', 'source_phone' => $phone]);

            $this->comms->sendSessionMessage($phone, $message->answer);
        } finally {
            if ($previousTenant !== null) {
                $currentTenant->set($previousTenant);
            } else {
                $currentTenant->clear();
            }
        }
    }
}
