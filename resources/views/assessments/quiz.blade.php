<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $quiz->title }} — {{ config('app.name') }}</title>
    @livewireStyles
</head>
<body>
    <livewire:quiz-runner :quiz="$quiz" />

    @livewireScripts
</body>
</html>
