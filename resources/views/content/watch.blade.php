<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $lesson->title }} — {{ config('app.name') }}</title>
    @livewireStyles
</head>
<body>
    @if ($lesson->type === 'video' || $lesson->type === 'live_session')
        <livewire:lesson-player :lesson="$lesson" />
        {{-- U2 — jump-to-timestamp markers, same video-timeline scoping as
             doubt-chat/revision-notes below; dispatches the player's own
             existing seek-to window event. --}}
        <livewire:ai.chapter-markers :lesson="$lesson" />
        <livewire:ai.doubt-chat :lesson="$lesson" />
        {{-- Timestamp-based, so only meaningful where there's an actual
             video timeline (design doc §3.1) — text/pdf/embed lessons have
             no timestamp_s to attach a marker to. --}}
        <livewire:content.lesson-notes :lesson="$lesson" />
        {{-- S17 B4 — revision notes summarize a transcript, so only
             meaningful where LessonArtifact ingestion actually runs
             (video/live_session), same scoping as doubt-chat above. --}}
        <livewire:ai.lesson-revision-notes :lesson="$lesson" />
    @elseif ($lesson->type === 'pdf')
        @if ($lesson->print_disabled)
            {{--
                Content protection (S14 B4/B5, design doc decision 9):
                #toolbar=0 suppresses the browser's own PDF-viewer toolbar
                where the given browser honors it (Chrome/Firefox only) — a
                deterrent, not real DRM, same honest framing S9 B6's own
                video protection already uses. oncontextmenu on the wrapping
                element stops the easiest right-click "Save As" path
                (fragment, not query string, so it works unchanged alongside
                the pdfUrl's own signed-route params). Opt-in per lesson
                (decision 10) — off by default, an instructor flips
                print_disabled on for content actually worth protecting.
            --}}
            <div oncontextmenu="return false">
                <iframe src="{{ $pdfUrl }}#toolbar=0" style="width: 100%; height: 90vh; border: 0;"></iframe>
            </div>
        @else
            <iframe src="{{ $pdfUrl }}" style="width: 100%; height: 90vh; border: 0;"></iframe>
        @endif
    @elseif ($lesson->type === 'text')
        {{-- Sanitized on save via Lesson's own SanitizesHtml trait — not
             re-sanitized here, the same trust boundary every other
             already-saved rich-text field in this app relies on. --}}
        @if ($lesson->print_disabled)
            {{--
                Content protection (S14 B5, design doc decision 9):
                oncontextmenu blocks the easiest right-click "Save As"/copy
                path; @media print hides the body so hitting Ctrl+P prints a
                blank page instead — defeats the single most common path,
                doesn't stop a screenshot, same honest framing everywhere
                else in this app already uses. Opt-in per lesson (decision
                10), same as the pdf branch above.
            --}}
            <style>@media print { .lesson-text-body { display: none; } }</style>
            <div class="lesson-text-body" oncontextmenu="return false">{!! $lesson->text_body !!}</div>
        @else
            <div>{!! $lesson->text_body !!}</div>
        @endif
    @elseif ($lesson->type === 'embed')
        {{-- embed_url is validated against Lesson::EMBEDDABLE_HOSTS at save
             time (LessonResource's own form rule) — not re-validated here. --}}
        <iframe src="{{ $lesson->embed_url }}" style="width: 100%; height: 90vh; border: 0;" allowfullscreen></iframe>
    @endif

    {{-- Every lesson type, not just video/live_session — the page itself
         is already gated by WatchLessonController's own canStream() check
         regardless of type (design doc decision 8). --}}
    <livewire:content.lesson-questions :lesson="$lesson" />

    {{-- S24 B10 — same route-param convention every other lessons.watch
         link on this page already uses. --}}
    <a href="{{ route('lessons.notes.print', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'lesson' => $lesson->id]) }}">Print notes</a>

    @livewireScripts
</body>
</html>
