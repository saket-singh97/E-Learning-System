<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate — {{ $course->title }}</title>
    <style>
        body {
            font-family: sans-serif; max-width: 700px; margin: 2rem auto; color: #111; text-align: center;
            @if ($backgroundPath ?? null)
                background-image: url('{{ $backgroundPath }}'); background-size: cover;
            @endif
        }
        .border { border: 4px double #333; padding: 3rem 2rem; background: rgba(255, 255, 255, 0.85); }
        .logo { max-height: 60px; margin-bottom: 1rem; }
        h1 { font-size: 1.4rem; letter-spacing: 0.1em; text-transform: uppercase; }
        .student-name { font-size: 1.8rem; margin: 1rem 0; }
        .course-title { font-size: 1.2rem; font-weight: bold; }
        table { width: 100%; margin-top: 2rem; font-size: 0.85rem; }
        th, td { text-align: left; padding: 0.3rem 0.6rem; }
        .signature { max-height: 50px; margin-top: 2rem; }
    </style>
</head>
<body>
    <div class="border">
        @if ($logoPath ?? null)
            <img class="logo" src="{{ $logoPath }}" alt="">
        @endif

        <p>{{ $tenant->legal_name ?? $tenant->name }}</p>
        <h1>Certificate of Completion</h1>
        <p>This certifies that</p>
        <p class="student-name">{{ $student->name }}</p>
        <p>has successfully completed</p>
        <p class="course-title">{{ $course->title }}</p>
        <p>with a final score of {{ number_format($finalScore, 1) }}%</p>

        @if ($signaturePath ?? null)
            <img class="signature" src="{{ $signaturePath }}" alt="Signature">
        @endif

        <table>
            <tr>
                <th>Issued</th>
                <td>{{ $issuedAt->format('Y-m-d') }}</td>
                <th>Verification ID</th>
                <td>{{ $verificationId }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
