<div>
    @if ($invoice === null)
        @if ($allInvoices->isNotEmpty())
            <p>All invoices for this enrolment are paid.</p>
        @else
            <em>No invoice found for this enrolment.</em>
        @endif
    @else
        <p>
            {{ $invoice->description }}: &#8377;{{ number_format((float) $invoice->total_amount, 2) }}
            (incl. GST) &mdash; due {{ $invoice->due_date->format('Y-m-d') }}
            @if ((float) $invoice->discount_amount > 0)
                <br>Coupon applied: &minus;&#8377;{{ number_format((float) $invoice->discount_amount, 2) }}
            @endif
        </p>

        @if ($invoice->coupon_id === null)
            <input type="text" wire:model="couponCode" placeholder="Coupon code">
            <button type="button" wire:click="applyCoupon">Apply coupon</button>
            @error('couponCode')
                <p><strong>{{ $message }}</strong></p>
            @enderror
        @endif

        @if ($latestPayment === null)
            <select wire:model="emiDurationMonths">
                <option value="">Full payment</option>
                <option value="3">Card EMI &mdash; 3 months</option>
                <option value="6">Card EMI &mdash; 6 months</option>
                <option value="9">Card EMI &mdash; 9 months</option>
                <option value="12">Card EMI &mdash; 12 months</option>
            </select>
            <button type="button" wire:click="startCheckout">Pay now</button>
        @elseif ($latestPayment->status === 'created')
            {{-- No broadcasting infrastructure exists yet (BROADCAST_CONNECTION
                 is 'log', per .env.example) — wire:poll is the pragmatic live-
                 refresh mechanism (S9 B8) rather than standing up real
                 broadcasting for this one need. Polls only while a payment is
                 genuinely in flight: this element (and the poll along with it)
                 stops rendering the moment the webhook flips status away from
                 'created', whichever way it goes. --}}
            <p wire:poll.3s>Order created ({{ $latestPayment->provider_order_id }}) &mdash; complete payment to finish enrolling.</p>
        @elseif ($latestPayment->status === 'failed')
            <p>Last attempt failed{{ $latestPayment->failure_reason ? ': '.$latestPayment->failure_reason : '.' }}</p>
            <button type="button" wire:click="startCheckout">Try again</button>
        @endif
    @endif

    @if ($allInvoices->isNotEmpty())
        <h3>Your invoices</h3>
        <ul>
            @foreach ($allInvoices as $row)
                <li wire:key="invoice-{{ $row->id }}">
                    {{ $row->invoice_number }} &mdash; {{ ucfirst($row->status) }} &mdash;
                    &#8377;{{ number_format((float) $row->total_amount, 2) }}
                    <a href="{{ route('invoices.show', ['subdomain' => $subdomain, 'invoice' => $row->id]) }}" target="_blank">View / print</a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
