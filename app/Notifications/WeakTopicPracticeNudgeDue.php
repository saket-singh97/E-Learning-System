<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * M15's weak-topic-practice nudge (S18 B7) — same student-facing,
 * dual-channel shape as InactivityNudgeDue/MissedClassReplayNudgeDue.
 * SendWeakTopicPracticeNudges owns the once-per-week dedupe and the
 * "only fire on a non-empty weakTopicsFor() result" gate; this class only
 * carries the already-computed weak topics
 * (WeakTopicDetectionService::weakTopicsFor()'s own return shape).
 */
class WeakTopicPracticeNudgeDue extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, array{concept: string, correct_count: int, total_count: int, ratio: float, lessons: Collection<int, \App\Domains\Content\Models\Lesson>}>  $weakTopics
     */
    public function __construct(
        public readonly Collection $weakTopics,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class, 'database'];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('weak_topic_practice_nudge', [
            'concepts' => $this->weakTopics->pluck('concept')->implode(', '),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'topics' => $this->weakTopics->map(fn (array $topic): array => [
                'concept' => $topic['concept'],
                'ratio' => $topic['ratio'],
                'lesson_ids' => $topic['lessons']->pluck('id')->values()->all(),
            ])->values()->all(),
        ];
    }
}
