<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }} — {{ config('app.name') }}</title>
    <style>
        body { font-family: sans-serif; max-width: 700px; margin: 2rem auto; color: #111; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid #ddd; }
        .total-row td { font-weight: bold; border-top: 2px solid #111; }
        .no-print { margin-top: 1.5rem; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <h1>Tax Invoice</h1>

    <table>
        <tr>
            <th>Invoice number</th>
            <td>{{ $invoice->invoice_number }}</td>
            <th>Invoice date</th>
            <td>{{ $invoice->created_at->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <th>Due date</th>
            <td>{{ $invoice->due_date->format('Y-m-d') }}</td>
            <th>Status</th>
            <td>{{ ucfirst($invoice->status) }}{{ $invoice->paid_at ? ' — paid '.$invoice->paid_at->format('Y-m-d') : '' }}</td>
        </tr>
    </table>

    <h2>From</h2>
    <p>
        {{ $tenant->legal_name ?? $tenant->name }}<br>
        @if ($tenant->billing_address)
            {{ $tenant->billing_address }}<br>
        @endif
        GSTIN: {{ $invoice->gstin_snapshot ?? 'Not registered' }}
    </p>

    <h2>Bill to</h2>
    <p>
        {{ $invoice->user->name }}<br>
        {{ $invoice->user->email }}
    </p>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>HSN/SAC</th>
                <th>Taxable value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $invoice->description }} — {{ $invoice->batch->course->title }} ({{ $invoice->batch->title }})</td>
                <td>9992</td>
                <td>{{ $invoice->currencySymbol() }}{{ number_format((float) $invoice->amount, 2) }}</td>
            </tr>
            @if ((float) $invoice->discount_amount > 0)
                <tr>
                    <td colspan="2">Discount{{ $invoice->coupon ? ' ('.$invoice->coupon->code.')' : '' }}</td>
                    <td>&minus;{{ $invoice->currencySymbol() }}{{ number_format((float) $invoice->discount_amount, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="2">{{ $tenant->tax_label ?? 'GST' }} ({{ rtrim(rtrim(number_format((float) $invoice->gst_rate, 2), '0'), '.') }}%)</td>
                <td>{{ $invoice->currencySymbol() }}{{ number_format((float) $invoice->gst_amount, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="2">Total</td>
                <td>{{ $invoice->currencySymbol() }}{{ number_format((float) $invoice->total_amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="no-print">
        <button type="button" onclick="window.print()">Print / save as PDF</button>
    </p>
</body>
</html>
