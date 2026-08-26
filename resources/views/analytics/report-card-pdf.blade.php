<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Monthly Report Card — {{ $student->name }}</title>
    <style>
        body { font-family: sans-serif; max-width: 700px; margin: 2rem auto; color: #111; }
        h1 { font-size: 1.3rem; }
        .meta { color: #555; margin-bottom: 1.5rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #ddd; }
    </style>
</head>
<body>
    <h1>{{ $tenant->name }} — Monthly Report Card</h1>
    <p class="meta">{{ $student->name }} &middot; {{ $periodMonth->format('F Y') }}</p>

    <table>
        <tr><th>Attendance</th><td>{{ number_format($summary['attendance_percent'], 1) }}%</td></tr>
        <tr><th>Quiz average</th><td>{{ number_format($summary['quiz_average_percent'], 1) }}%</td></tr>
        <tr><th>Engagement ratio</th><td>{{ number_format($summary['engagement_ratio_percent'], 1) }}%</td></tr>
    </table>
</body>
</html>
