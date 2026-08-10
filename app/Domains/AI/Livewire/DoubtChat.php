<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Services\DoubtChatService;
use App\Domains\Content\Models\Lesson;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class DoubtChat extends Component
{
    public Lesson $lesson;

    public string $question = '';

    /** @var Collection<int, DoubtChatMessage> */
    public Collection $messages;

    public function mount(Lesson $lesson): void
    {
        $this->lesson = $lesson;

        $this->messages = auth()->check()
            ? DoubtChatMessage::where('lesson_id', $lesson->id)
                ->where('user_id', auth()->id())
                ->orderBy('created_at')
                ->get()
            : collect();
    }

    public function ask(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $this->validate(['question' => 'required|string|max:2000']);

        $this->messages->push(app(DoubtChatService::class)->ask($this->lesson, $user, $this->question));

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
