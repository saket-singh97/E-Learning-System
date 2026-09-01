<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $lesson->title }} — Notes</title>
    <style>
        /* Print-only chrome: the on-screen "Print" trigger never reaches
           paper, and there's no site nav/branding on this page to begin
           with — the emptiness itself is the rest of the "hide all
           chrome" job. */
        @media print {
            .no-print { display: none; }
        }
    </style>
    @livewireStyles
</head>
<body>
    <button class="no-print" type="button" onclick="window.print()">Print</button>

    <h1>{{ $lesson->title }}</h1>

    <livewire:ai.lesson-revision-notes :lesson="$lesson" />
    <livewire:content.lesson-notes :lesson="$lesson" />

    @livewireScripts
</body>
</html>
