<?php

namespace App\Domains\Comms\Livewire;

use App\Domains\Comms\Models\ForumPost;
use App\Domains\Comms\Models\ForumThread;
use App\Domains\Comms\Services\ForumPostComposer;
use App\Domains\Comms\Services\ForumThreadAccess;
use App\Exceptions\ForumReplyDepthExceededException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * S19 N14 — institute-wide (`batch_id IS NULL`, B2's own nullable-column
 * migration), not gated by batch enrolment: any authenticated tenant user
 * can read and post, same "any tenant user" reach `DirectMessages` already
 * has. Reuses `ForumPost`/`ForumThread`/`ForumPostComposer` wholesale
 * (design doc §4 decision 5) — same shape `BatchForum` already established,
 * with the batch/enrolment gate removed rather than threaded through it as
 * a nullable case.
 *
 * `render()` gates `threads()`/`postTree()` behind `canParticipate` from the
 * start (not left for a later review pass to catch, the way `BatchForum`'s
 * own B21 fix had to) — the route this embeds on carries no auth
 * middleware, same "guest sees a log-in prompt" reasoning `routes/comms.php`
 * already documents for `/messages` and `/batches/{batch}/forum`.
 */
class GeneralForum extends Component
{
    public ?int $openThreadId = null;

    public string $newThreadTitle = '';

    public string $newThreadBody = '';

    /** @var array<int, string> */
    public array $replyBodies = [];

    public function threads(): Collection
    {
        return ForumThread::whereNull('batch_id')
            ->withCount('posts')
            ->orderByDesc('created_at')
            ->get();
    }

    public function openThread(int $threadId): void
    {
        $thread = ForumThread::whereNull('batch_id')->find($threadId);

        if ($thread !== null && $this->canParticipate(auth()->user(), $thread)) {
            $this->openThreadId = $thread->id;
        }
    }

    public function closeThread(): void
    {
        $this->openThreadId = null;
    }

    /**
     * Called by the client's Echo listener on `forum-thread.{id}` (S19 N14
     * pt.2) when a `post.created` broadcast arrives for the open thread.
     * The payload carries nothing this method needs to read — any Livewire
     * round trip re-renders `postTree()` fresh from the database.
     */
    public function receivePost(array $payload): void
    {
        //
    }

    public function createThread(): void
    {
        $viewer = auth()->user();

        if (! $viewer || ! $this->canParticipate($viewer)) {
            return;
        }

        $this->validate([
            'newThreadTitle' => 'required|string|max:255',
            'newThreadBody' => 'required|string|max:5000',
        ]);

        $thread = ForumThread::create([
            'batch_id' => null,
            'title' => $this->newThreadTitle,
            'created_by_user_id' => $viewer->id,
        ]);

        app(ForumPostComposer::class)->post($thread, null, $viewer, $this->newThreadBody);

        $this->newThreadTitle = '';
        $this->newThreadBody = '';
        $this->openThreadId = $thread->id;
    }

    public function reply(int $parentPostId): void
    {
        $viewer = auth()->user();

        if (! $viewer || $this->openThreadId === null) {
            return;
        }

        $thread = ForumThread::whereNull('batch_id')->findOrFail($this->openThreadId);

        if (! $this->canParticipate($viewer, $thread)) {
            return;
        }

        $this->validate(["replyBodies.{$parentPostId}" => 'required|string|max:5000']);

        $parent = ForumPost::where('forum_thread_id', $thread->id)->findOrFail($parentPostId);

        try {
            app(ForumPostComposer::class)->post($thread, $parent, $viewer, $this->replyBodies[$parentPostId]);
        } catch (ForumReplyDepthExceededException $e) {
            $this->addError("replyBodies.{$parentPostId}", $e->getMessage());

            return;
        }

        $this->replyBodies[$parentPostId] = '';
    }

    /**
     * Flat query, nested in PHP — same shape `BatchForum::postTree()`
     * already established for the same bound-depth (2) tree.
     *
     * @return Collection<int, ForumPost>
     */
    protected function postTree(): Collection
    {
        if ($this->openThreadId === null) {
            return collect();
        }

        $posts = ForumPost::where('forum_thread_id', $this->openThreadId)
            ->with('author')
            ->orderBy('created_at')
            ->get();

        return $this->attachReplies(null, $posts);
    }

    /** @return Collection<int, ForumPost> */
    protected function attachReplies(?int $parentId, Collection $all): Collection
    {
        return $all->where('parent_id', $parentId)
            ->map(function (ForumPost $post) use ($all): ForumPost {
                $post->setRelation('childReplies', $this->attachReplies($post->id, $all));

                return $post;
            })
            ->values();
    }

    /**
     * Delegates to `ForumThreadAccess` (same "single source of truth"
     * discipline `LessonAccess` established, CLAUDE.md) whenever a thread is
     * already in hand — `openThread()`/`reply()` both have one. `createThread()`
     * and the initial `render()` gate have no thread yet (one doesn't exist
     * until this method already said yes), so they fall back to the same
     * "any tenant user" rule `ForumThreadAccess::canParticipateInThread()`
     * applies to a null-`batch_id` thread, checked against `CurrentTenant`
     * explicitly rather than trusting `ForumThread`'s own global scope alone.
     */
    protected function canParticipate(?User $viewer, ?ForumThread $thread = null): bool
    {
        if ($viewer === null) {
            return false;
        }

        if ($thread !== null) {
            return ForumThreadAccess::canParticipateInThread($thread, $viewer);
        }

        $tenant = app(CurrentTenant::class);

        return $tenant->isBound() && $viewer->tenant_id === $tenant->id();
    }

    public function render(): View
    {
        $viewer = auth()->user();
        $canParticipate = $this->canParticipate($viewer);

        return view('comms::general-forum', [
            'threads' => $canParticipate ? $this->threads() : collect(),
            'posts' => $canParticipate ? $this->postTree() : collect(),
            'canParticipate' => $canParticipate,
        ]);
    }
}
