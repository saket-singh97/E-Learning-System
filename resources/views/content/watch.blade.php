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
    @elseif ($lesson->type === 'pdf')
        <iframe src="{{ $pdfUrl }}" style="width: 100%; height: 90vh; border: 0;"></iframe>
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

    @livewireScripts
</body>
</html>
