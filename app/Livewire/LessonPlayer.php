<?php

namespace App\Livewire;

use App\Domains\Content\Models\Lesson;
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

        if ($user) {
            $this->resumeAt = (int) VideoWatchEvent::where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->max('end_s');
        }
    }

    public function render(): View
    {
        return view('livewire.lesson-player');
    }
}
