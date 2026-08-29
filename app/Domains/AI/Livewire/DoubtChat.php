<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Services\DoubtChatService;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Services\FeatureGate;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class DoubtChat extends Component
{
    public Lesson $lesson;

    public string $question = '';

    public bool $featureEnabled = false;

    /** @var Collection<int, DoubtChatMessage> */
    public Collection $messages;

    public function mount(Lesson $lesson): void
    {
        $this->lesson = $lesson;
        $this->featureEnabled = app(FeatureGate::class)->enabled(app(CurrentTenant::class)->get(), 'doubt_chat');

        $this->messages = $this->featureEnabled && auth()->check()
            ? DoubtChatMessage::where('lesson_id', $lesson->id)
                ->where('user_id', auth()->id())
                ->orderBy('created_at')
                ->get()
            : collect();
    }

    /**
     * U4 (S22 B5) — $atSecond comes from the player's own existing
     * `lesson-time-update` window event (lesson-player.blade.php already
     * dispatches it every timeupdate), read client-side and passed through
     * `$wire.ask(...)` when the student opts into "ask about this moment";
     * omitted (null) otherwise, so DoubtChatService::ask() takes its
     * existing whole-lesson path unchanged.
     */
    public function ask(?int $atSecond = null): void
    {
        $user = auth()->user();

        if (! $user || ! $this->featureEnabled) {
            return;
        }

        $this->validate(['question' => 'required|string|max:2000']);

        $this->messages->push(app(DoubtChatService::class)->ask($this->lesson, $user, $this->question, $atSecond));

        $this->question = '';
    }

    /**
     * cited_chunk_idxs only stores indexes (S3's own schema addition, not S1
     * §5 — kept minimal on purpose). Resolved against the lesson's current
     * latest-ready artifact for display; fine for a live chat session.
     *
     * @return Collection<int, LessonArtifactChunk>
     */
    public function citationsFor(DoubtChatMessage $message): Collection
    {
        if (empty($message->cited_chunk_idxs)) {
            return collect();
        }

        $artifact = LessonArtifact::latestReadyFor($this->lesson->id);

        if (! $artifact) {
            return collect();
        }

        return LessonArtifactChunk::where('lesson_artifact_id', $artifact->id)
            ->whereIn('chunk_idx', $message->cited_chunk_idxs)
            ->orderBy('chunk_idx')
            ->get();
    }

    public function render(): View
    {
        return view('ai::doubt-chat');
    }
}
