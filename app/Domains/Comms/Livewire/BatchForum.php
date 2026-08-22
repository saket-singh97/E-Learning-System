<?php

namespace App\Domains\Comms\Livewire;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Models\ForumPost;
use App\Domains\Comms\Models\ForumThread;
use App\Domains\Comms\Services\ForumPostComposer;
use App\Exceptions\ForumReplyDepthExceededException;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Batch-scoped (F6), gated by active enrolment in the batch — same
 * canStream()-style gate shape LessonQuestions already established
 * (design doc §3.1), adapted here to batch membership: the batch's own
 * assigned instructor and institute_admin bypass enrolment (mirrors
 * LessonAccess::canJoinLive()'s staff bypass), everyone else needs an
 * active Enrolment. This component's own /livewire/update calls re-check
 * canParticipate() on every action, same reasoning LessonQuestions'
 * own docblock already documents — the page load gating this embeds on
 * is a separate, unguarded request.
 */
class BatchForum extends Component
{
    public Batch $batch;

    public ?int $openThreadId = null;

    public string $newThreadTitle = '';

    public string $newThreadBody = '';

    /** @var array<int, string> */
    public array $replyBodies = [];

    public function mount(Batch $batch): void
    {
        $this->batch = $batch;
    }

    public function threads(): Collection
    {
        return ForumThread::where('batch_id', $this->batch->id)
            ->withCount('posts')
            ->orderByDesc('created_at')
            ->get();
    }

    public function openThread(int $threadId): void
    {
        $thread = ForumThread::where('batch_id', $this->batch->id)->find($threadId);

        if ($thread !== null) {
            $this->openThreadId = $thread->id;
        }
    }

    public function closeThread(): void
    {
        $this->openThreadId = null;
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
            'batch_id' => $this->batch->id,
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

        if (! $viewer || ! $this->canParticipate($viewer) || $this->openThreadId === null) {
            return;
        }

        $this->validate(["replyBodies.{$parentPostId}" => 'required|string|max:5000']);

        $thread = ForumThread::where('batch_id', $this->batch->id)->findOrFail($this->openThreadId);
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
     * Flat query, nested in PHP (depth is capped at 2, three small
     * groupings — no need for a recursive query or Blade recursion for a
     * bound-depth tree this shallow).
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

    /**
     * @return Collection<int, ForumPost>
     */
    protected function attachReplies(?int $parentId, Collection $all): Collection
    {
        return $all->where('parent_id', $parentId)
            ->map(function (ForumPost $post) use ($all): ForumPost {
                $post->setRelation('childReplies', $this->attachReplies($post->id, $all));

                return $post;
            })
            ->values();
    }

    protected function canParticipate(?User $viewer): bool
    {
        if ($viewer === null || $viewer->tenant_id !== $this->batch->tenant_id) {
            return false;
        }

        if ($viewer->hasRole('institute_admin')) {
            return true;
        }

        if ($viewer->id === $this->batch->instructor_id) {
            return true;
        }

        return Enrolment::where('batch_id', $this->batch->id)
            ->where('user_id', $viewer->id)
            ->where('status', 'active')
            ->exists();
    }

    public function render(): View
    {
        $viewer = auth()->user();

        return view('comms::batch-forum', [
            'threads' => $this->threads(),
            'posts' => $this->postTree(),
            'canParticipate' => $this->canParticipate($viewer),
        ]);
    }
}
