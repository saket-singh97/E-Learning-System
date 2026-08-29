<?php

namespace App\Livewire;

use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\LessonAudioTrack;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

class LessonPlayer extends Component
{
    public Lesson $lesson;

    public string $streamUrl = '';

    public string $heartbeatUrl = '';

    public string $subtitleUrl = '';

    public string $audioUrl = '';

    public string $viewerName = '';

    public string $viewerEmail = '';

    public int $resumeAt = 0;

    public function mount(Lesson $lesson): void
    {
        $this->lesson = $lesson;

        $user = auth()->user();
        $this->viewerName = $user?->name ?? 'Guest';
        $this->viewerEmail = $user?->email ?? '';

        $subdomain = app(CurrentTenant::class)->get()->subdomain;

        $this->streamUrl = URL::temporarySignedRoute('watch.stream', now()->addMinutes(10), [
            'subdomain' => $subdomain,
            'lesson' => $lesson->id,
        ]);

        $this->heartbeatUrl = URL::route('watch.heartbeat', [
            'subdomain' => $subdomain,
            'lesson' => $lesson->id,
        ]);

        // D13 pt.2 — only a lesson with a generated/edited VTT file gets a
        // <track> src at all; most lessons never reach 'ready' here.
        if ($lesson->subtitle_status === 'ready' && filled($lesson->subtitle_disk_path)) {
            $this->subtitleUrl = URL::temporarySignedRoute('watch.subtitle', now()->addMinutes(10), [
                'subdomain' => $subdomain,
                'lesson' => $lesson->id,
            ]);
        }

        if ($user) {
            $this->resumeAt = (int) VideoWatchEvent::where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->max('end_s');
        }

        // U7 pt.2 (S22 B8) — the toggle only ever appears when a ready
        // track actually exists (design doc B8 checklist).
        $hasReadyAudioTrack = LessonAudioTrack::where('lesson_id', $lesson->id)
            ->where('status', 'ready')
            ->exists();

        if ($hasReadyAudioTrack) {
            $this->audioUrl = URL::temporarySignedRoute('watch.audio', now()->addMinutes(10), [
                'subdomain' => $subdomain,
                'lesson' => $lesson->id,
            ]);
        }
    }

    public function render(): View
    {
        return view('livewire.lesson-player');
    }
}
