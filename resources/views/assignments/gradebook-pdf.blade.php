<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Gradebook</title>
    <style>
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <h1>Gradebook</h1>
    <table>
        <thead>
            <tr>
                <th>Student name</th>
                <th>Student email</th>
                <th>Course</th>
                <th>Quiz %</th>
                <th>Assignment %</th>
                <th>Weighted %</th>
                <th>Standing</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['email'] }}</td>
                    <td>{{ $row['course'] }}</td>
                    <td>{{ $row['quiz_percent'] ?? '—' }}</td>
                    <td>{{ $row['assignment_percent'] ?? '—' }}</td>
                    <td>{{ $row['weighted_percent'] }}</td>
                    <td>{{ $row['standing'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
