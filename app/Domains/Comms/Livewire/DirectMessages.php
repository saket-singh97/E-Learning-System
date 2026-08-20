<?php

namespace App\Domains\Comms\Livewire;

use App\Domains\Comms\Models\BlockedUser;
use App\Domains\Comms\Models\DirectMessage;
use App\Domains\Comms\Models\MessageReport;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * 1:1, tenant-scoped (design doc §3.2) — public, no route-level auth gate
 * (same "no single named login route" reasoning B3's own docblock already
 * established for MyWishlist); a guest sees an empty inbox/prompt state.
 */
class DirectMessages extends Component
{
    public ?int $recipientId = null;

    public string $newMessageBody = '';

    /** @var array<int, string> */
    public array $reportReasons = [];

    /**
     * Every user in the tenant this viewer has exchanged a message with,
     * newest activity first.
     */
    public function conversationPartnerIds(): Collection
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return collect();
        }

        return DirectMessage::where('sender_id', $viewer->id)
            ->orWhere('recipient_id', $viewer->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DirectMessage $message) => $message->sender_id === $viewer->id ? $message->recipient_id : $message->sender_id)
            ->unique()
            ->values();
    }

    /**
     * Selecting a thread also marks the viewer's own unread messages in it
     * as read — same "opening it is reading it" behaviour any inbox has.
     * Called both from the conversation-list buttons (a literal arg) and
     * from the "start a new conversation" select (wire:model.live, via
     * updatedRecipientId() below) — one shared path either way.
     */
    public function selectConversation(int $userId): void
    {
        $this->recipientId = $userId;

        $this->markThreadRead($userId);
    }

    /**
     * Livewire's own magic hook, fires when $recipientId changes through a
     * client-bound update (wire:model.live) — the conversation-list
     * buttons above set the property directly instead, which doesn't
     * trigger this, so selectConversation() calls markThreadRead() itself
     * too rather than relying on this hook alone.
     */
    public function updatedRecipientId(): void
    {
        if ($this->recipientId) {
            $this->markThreadRead($this->recipientId);
        }
    }

    protected function markThreadRead(int $senderId): void
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return;
        }

        DirectMessage::where('sender_id', $senderId)
            ->where('recipient_id', $viewer->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * A message with an `actioned` report (design doc decision 10) is
     * excluded here — the row itself is untouched (flag, don't auto-act),
     * this is purely a display-time hide, the same "not an automated
     * takedown" posture the decision itself describes.
     */
    public function threadMessages(): Collection
    {
        $viewer = auth()->user();

        if (! $viewer || ! $this->recipientId) {
            return collect();
        }

        return DirectMessage::where(fn ($query) => $query
            ->where('sender_id', $viewer->id)
            ->where('recipient_id', $this->recipientId))
            ->orWhere(fn ($query) => $query
                ->where('sender_id', $this->recipientId)
                ->where('recipient_id', $viewer->id))
            ->whereDoesntHave('reports', fn ($query) => $query->where('status', 'actioned'))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Only the other party's own messages, matching real "report abuse"
     * semantics — a viewer reporting their own sent message is
     * nonsensical, and the message must actually be in this viewer's own
     * thread (can't report an arbitrary id from outside it).
     */
    public function reportMessage(int $messageId): void
    {
        $viewer = auth()->user();

        if (! $viewer || ! $this->recipientId) {
            return;
        }

        $message = DirectMessage::where('id', $messageId)
            ->where('sender_id', $this->recipientId)
            ->where('recipient_id', $viewer->id)
            ->first();

        if (! $message) {
            return;
        }

        $this->validate(["reportReasons.{$messageId}" => 'required|string|max:1000']);

        MessageReport::create([
            'reporter_id' => $viewer->id,
            'direct_message_id' => $message->id,
            'reason' => $this->reportReasons[$messageId],
        ]);

        $this->reportReasons[$messageId] = '';
    }

    /**
     * Symmetric at read/send time, not write-enforced both directions
     * (design doc decision 9) — blocked_users stores one directed row, but
     * either party blocking the other silences the thread here.
     */
    protected function isBlockedWith(int $userId): bool
    {
        $viewer = auth()->user();

        return BlockedUser::where(fn ($query) => $query
            ->where('user_id', $viewer->id)
            ->where('blocked_user_id', $userId))
            ->orWhere(fn ($query) => $query
                ->where('user_id', $userId)
                ->where('blocked_user_id', $viewer->id))
            ->exists();
    }

    public function send(): void
    {
        $viewer = auth()->user();

        if (! $viewer || ! $this->recipientId || $this->recipientId === $viewer->id) {
            return;
        }

        $recipient = User::where('tenant_id', $viewer->tenant_id)->find($this->recipientId);

        if (! $recipient || $this->isBlockedWith($recipient->id)) {
            return;
        }

        $this->validate(['newMessageBody' => 'required|string|max:2000']);

        DirectMessage::create([
            'sender_id' => $viewer->id,
            'recipient_id' => $recipient->id,
            'body' => $this->newMessageBody,
        ]);

        $this->newMessageBody = '';
    }

    /**
     * Toggled on/off, same shape WishlistService::toggle() already
     * established (B3) — one row per direction, not per action.
     */
    public function toggleBlock(int $userId): void
    {
        $viewer = auth()->user();

        if (! $viewer || $userId === $viewer->id) {
            return;
        }

        $existing = BlockedUser::where('user_id', $viewer->id)->where('blocked_user_id', $userId)->first();

        if ($existing) {
            $existing->delete();

            return;
        }

        BlockedUser::create(['user_id' => $viewer->id, 'blocked_user_id' => $userId]);
    }

    public function render(): View
    {
        $viewer = auth()->user();

        return view('comms::direct-messages', [
            'conversationPartners' => $viewer
                ? User::whereIn('id', $this->conversationPartnerIds())->get()
                : collect(),
            'otherUsers' => $viewer
                ? User::where('tenant_id', $viewer->tenant_id)->where('id', '!=', $viewer->id)->orderBy('name')->get()
                : collect(),
            'messages' => $this->threadMessages(),
            'blockedUserIds' => $viewer
                ? BlockedUser::where('user_id', $viewer->id)->pluck('blocked_user_id')->all()
                : [],
        ]);
    }
}
