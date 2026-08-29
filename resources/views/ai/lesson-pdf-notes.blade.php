<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $lesson->title }} — Lecture Notes</title>
</head>
<body>
    <h1>{{ $lesson->title }}</h1>
    <ul>
        @foreach ($notes as $point)
            <li>{{ $point }}</li>
        @endforeach
    </ul>
</body>
</html>
