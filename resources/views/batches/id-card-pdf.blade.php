<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>ID Card — {{ $student->name }}</title>
    <style>
        body { font-family: sans-serif; max-width: 400px; margin: 2rem auto; color: #111; }
        .card { border: 2px solid #333; border-radius: 8px; padding: 1.5rem; text-align: center; }
        .student-name { font-size: 1.3rem; font-weight: bold; margin: 0.5rem 0; }
        .batch-title { font-size: 0.95rem; color: #444; }
        .qr { max-height: 120px; margin: 1rem 0; }
        table { width: 100%; margin-top: 1rem; font-size: 0.75rem; }
        th, td { text-align: left; padding: 0.2rem 0.4rem; }
    </style>
</head>
<body>
    <div class="card">
        <p class="student-name">{{ $student->name }}</p>
        <p class="batch-title">{{ $batch->title }}</p>

        <img class="qr" src="{{ $qrDataUri }}" alt="QR code">

        <table>
            <tr>
                <th>Card No.</th>
                <td>{{ $cardNumber }}</td>
            </tr>
            <tr>
                <th>Issued</th>
                <td>{{ $issuedAt->format('Y-m-d') }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
