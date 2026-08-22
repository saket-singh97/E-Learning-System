<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>
    @livewireStyles
</head>
<body>
    <livewire:instructor-invitation-accept :token="$token" />

    @livewireScripts
</body>
</html>
