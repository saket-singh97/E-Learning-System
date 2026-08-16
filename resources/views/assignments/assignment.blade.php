<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $assignment->title }} — {{ config('app.name') }}</title>
    @livewireStyles
</head>
<body>
    <livewire:assignment-submit :assignment="$assignment" />

    @livewireScripts
</body>
</html>
