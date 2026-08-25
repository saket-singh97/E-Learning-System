<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; position: relative; }
        .bubble { position: absolute; width: 16px; height: 16px; border: 1px solid #000; border-radius: 50%; font-size: 8px; text-align: center; }
        .label { position: absolute; font-size: 9px; }
    </style>
</head>
<body>
    <h3>{{ $quiz->title }} — OMR Sheet</h3>
    <p>Roll number ({{ $template->roll_number_digits }} digits) — fill one bubble per column.</p>

    @foreach ($template->layout['roll_number'] as $column)
        @foreach ($column['digits'] as $bubble)
            <div class="bubble" style="left: {{ $bubble['x'] }}px; top: {{ $bubble['y'] }}px;">{{ $bubble['digit'] }}</div>
        @endforeach
    @endforeach

    @foreach ($template->layout['questions'] as $question)
        <div class="label" style="left: 10px; top: {{ $question['options'][0]['y'] ?? 0 }}px;">Q{{ $question['question_index'] + 1 }}</div>
        @foreach ($question['options'] as $option)
            <div class="bubble" style="left: {{ $option['x'] }}px; top: {{ $option['y'] }}px;">{{ chr(65 + $option['option_index']) }}</div>
        @endforeach
    @endforeach
</body>
</html>
