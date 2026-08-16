<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate verification — {{ $certificate->verification_id }}</title>
    <meta property="og:title" content="{{ $certificate->user->name }} — {{ $certificate->course->title }} certificate">
    <meta property="og:description" content="Issued by {{ $tenant->legal_name ?? $tenant->name }} on {{ $certificate->issued_at->format('Y-m-d') }}.">
    @if ($ogImageUrl)
        <meta property="og:image" content="{{ $ogImageUrl }}">
    @endif
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 2rem auto; color: #111; }
        .verified { color: #15803d; font-weight: bold; }
        table { width: 100%; margin-top: 1rem; }
        th, td { text-align: left; padding: 0.3rem 0.6rem; }
        .qr { margin-top: 1.5rem; }
        .linkedin { display: inline-block; margin-top: 1.5rem; padding: 0.5rem 1rem; background: #0a66c2; color: #fff; text-decoration: none; border-radius: 4px; }
    </style>
</head>
<body>
    <p class="verified">&#10003; Verified certificate</p>
    <h1>{{ $certificate->user->name }}</h1>
    <p>completed <strong>{{ $certificate->course->title }}</strong> at {{ $tenant->legal_name ?? $tenant->name }}</p>

    <table>
        <tr>
            <th>Final score</th>
            <td>{{ number_format((float) $certificate->final_score, 1) }}%</td>
        </tr>
        <tr>
            <th>Issued</th>
            <td>{{ $certificate->issued_at->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <th>Verification ID</th>
            <td>{{ $certificate->verification_id }}</td>
        </tr>
    </table>

    <a class="linkedin" href="{{ $linkedInShareUrl }}" target="_blank" rel="noopener">Add to LinkedIn profile</a>

    <div class="qr">
        {!! $qrCodeSvg !!}
    </div>
</body>
</html>
