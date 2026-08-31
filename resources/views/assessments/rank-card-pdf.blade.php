<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rank Card — {{ $student->name }}</title>
    <style>
        body { font-family: sans-serif; max-width: 500px; margin: 2rem auto; color: #111; }
        .card { border: 2px solid #333; border-radius: 8px; padding: 1.5rem; }
        .student-name { font-size: 1.3rem; font-weight: bold; margin: 0.5rem 0; }
        .series-title { font-size: 0.95rem; color: #444; }
        .stats { width: 100%; margin-top: 1rem; font-size: 0.85rem; }
        .stats th, .stats td { text-align: left; padding: 0.3rem 0.5rem; }
        table.breakdown { width: 100%; margin-top: 1rem; font-size: 0.75rem; border-collapse: collapse; }
        table.breakdown th, table.breakdown td { text-align: left; padding: 0.2rem 0.4rem; border-bottom: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="card">
        <p class="student-name">{{ $student->name }}</p>
        <p class="series-title">{{ $series->title }}</p>

        <table class="stats">
            <tr>
                <th>Rank</th>
                <td>{{ $rank ?? '—' }}</td>
            </tr>
            <tr>
                <th>Percentile</th>
                <td>{{ $percentile }}%</td>
            </tr>
            <tr>
                <th>Total Score</th>
                <td>{{ $totalScore }}</td>
            </tr>
            <tr>
                <th>Generated</th>
                <td>{{ $generatedAt->format('Y-m-d') }}</td>
            </tr>
        </table>

        <table class="breakdown">
            <tr>
                <th>Subject</th>
                <th>Score</th>
            </tr>
            @forelse ($breakdown as $concept => $score)
                <tr>
                    <td>{{ $concept }}</td>
                    <td>{{ $score }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="2">No graded attempts yet.</td>
                </tr>
            @endforelse
        </table>
    </div>
</body>
</html>
