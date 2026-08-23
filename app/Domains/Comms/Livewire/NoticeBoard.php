<?php

namespace App\Domains\Comms\Livewire;

use App\Domains\Comms\Models\Notice;
use App\Domains\Comms\Models\NoticeReadReceipt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Portal/panel banner (S6 pt.2) — reused as-is on both the student and
 * parent dashboards, same "one component, embedded wherever needed"
 * shape NotificationCentre already established. Shows only *published*
 * notices whose `audience_roles` includes one of the viewer's own roles
 * (design decision 11 — a broadcast to a role, not a per-recipient
 * target); read state is per-user "seen" tracking only via
 * `notice_read_receipts`, not delivery targeting.
 */
class NoticeBoard extends Component
{
    public function notices(): Collection
    {
        $viewer = auth()->user();

        if ($viewer === null) {
            return collect();
        }

        $roles = $viewer->getRoleNames()->all();

        return Notice::whereNotNull('published_at')
            ->where(function (Builder $query) use ($roles): void {
                foreach ($roles as $role) {
                    $query->orWhereJsonContains('audience_roles', $role);
                }
            })
            ->orderByDesc('published_at')
            ->get();
    }

    public function markAsRead(int $noticeId): void
    {
        $viewer = auth()->user();

        if ($viewer === null) {
            return;
        }

        NoticeReadReceipt::firstOrCreate(
            ['notice_id' => $noticeId, 'user_id' => $viewer->id],
            ['read_at' => now()],
        );
    }

    public function render(): View
    {
        $viewer = auth()->user();
        $notices = $this->notices();

        return view('comms::notice-board', [
            'notices' => $notices,
            'readNoticeIds' => $viewer
                ? NoticeReadReceipt::where('user_id', $viewer->id)
                    ->whereIn('notice_id', $notices->pluck('id'))
                    ->pluck('notice_id')
                    ->all()
                : [],
        ]);
    }
}
