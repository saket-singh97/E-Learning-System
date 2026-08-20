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
        <livewire:ai.doubt-chat :lesson="$lesson" />
        {{-- Timestamp-based, so only meaningful where there's an actual
             video timeline (design doc §3.1) — text/pdf/embed lessons have
             no timestamp_s to attach a marker to. --}}
        <livewire:content.lesson-notes :lesson="$lesson" />
    @elseif ($lesson->type === 'pdf')
        {{--
            Content protection (S14 B4, design doc decision 9): #toolbar=0
            suppresses the browser's own PDF-viewer toolbar where the given
            browser honors it (Chrome/Firefox only) — a deterrent, not real
            DRM, same honest framing S9 B6's own video protection already
            uses. oncontextmenu on the wrapping element stops the easiest
            right-click "Save As" path (fragment, not query string, so it
            works unchanged alongside the pdfUrl's own signed-route params).
        --}}
        <div oncontextmenu="return false">
            <iframe src="{{ $pdfUrl }}#toolbar=0" style="width: 100%; height: 90vh; border: 0;"></iframe>
        </div>
    @elseif ($lesson->type === 'text')
        {{-- Sanitized on save via Lesson's own SanitizesHtml trait — not
             re-sanitized here, the same trust boundary every other
             already-saved rich-text field in this app relies on. --}}
        <div>{!! $lesson->text_body !!}</div>
    @elseif ($lesson->type === 'embed')
        {{-- embed_url is validated against Lesson::EMBEDDABLE_HOSTS at save
             time (LessonResource's own form rule) — not re-validated here. --}}
        <iframe src="{{ $lesson->embed_url }}" style="width: 100%; height: 90vh; border: 0;" allowfullscreen></iframe>
    @endif

    {{-- Every lesson type, not just video/live_session — the page itself
         is already gated by WatchLessonController's own canStream() check
         regardless of type (design doc decision 8). --}}
    <livewire:content.lesson-questions :lesson="$lesson" />

    @livewireScripts
</body>
</html>
