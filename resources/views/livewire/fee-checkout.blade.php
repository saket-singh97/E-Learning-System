<div>
    @if ($invoice === null)
        @if ($allInvoices->isNotEmpty())
            <p class="lms-meta">All invoices for this enrolment are paid.</p>
        @else
            <p class="lms-meta"><em>No invoice found for this enrolment.</em></p>
        @endif
    @else
        <p class="lms-meta">
            {{ $invoice->description }}: &#8377;{{ number_format((float) $invoice->total_amount, 2) }}
            (incl. GST) &mdash; due {{ $invoice->due_date->format('Y-m-d') }}
            @if ((float) $invoice->discount_amount > 0)
                <br>Coupon applied: &minus;&#8377;{{ number_format((float) $invoice->discount_amount, 2) }}
            @endif
        </p>

        @if ($invoice->coupon_id === null)
            <div style="display:flex; gap:0.5rem; align-items:flex-start; margin-top:0.5rem;">
                <label class="lms-field" style="flex:1; margin:0;">
                    <input class="lms-input" type="text" wire:model="couponCode" placeholder="Coupon code">
                </label>
                <button type="button" class="lms-button lms-button-secondary" style="width:auto;" wire:click="applyCoupon">Apply coupon</button>
            </div>
            @error('couponCode')
                <p class="lms-error">{{ $message }}</p>
            @enderror
        @endif

        @if ($latestPayment === null)
            <label class="lms-field">
                <span class="lms-field-label">Payment plan</span>
                <select class="lms-input" wire:model="emiDurationMonths">
                    <option value="">Full payment</option>
                    <option value="3">Card EMI &mdash; 3 months</option>
                    <option value="6">Card EMI &mdash; 6 months</option>
                    <option value="9">Card EMI &mdash; 9 months</option>
                    <option value="12">Card EMI &mdash; 12 months</option>
                </select>
            </label>
            <button type="button" class="lms-button" style="width:auto;" wire:click="startCheckout">Pay now</button>
        @elseif ($latestPayment->status === 'created')
            {{-- No broadcasting infrastructure exists yet (BROADCAST_CONNECTION
                 is 'log', per .env.example) — wire:poll is the pragmatic live-
                 refresh mechanism (S9 B8) rather than standing up real
                 broadcasting for this one need. Polls only while a payment is
                 genuinely in flight: this element (and the poll along with it)
                 stops rendering the moment the webhook flips status away from
                 'created', whichever way it goes. --}}
            <p class="lms-meta" wire:poll.3s>Order created ({{ $latestPayment->provider_order_id }}) &mdash; complete payment to finish enrolling.</p>
        @elseif ($latestPayment->status === 'failed')
            <p class="lms-error">Last attempt failed{{ $latestPayment->failure_reason ? ': '.$latestPayment->failure_reason : '.' }}</p>
            <button type="button" class="lms-button" style="width:auto;" wire:click="startCheckout">Try again</button>
        @endif
    @endif

    @if ($allInvoices->isNotEmpty())
        <hr class="lms-divider">
        <h3 class="lms-field-label">Your invoices</h3>
        <ul>
            @foreach ($allInvoices as $row)
                <li class="lms-list-item" wire:key="invoice-{{ $row->id }}">
                    {{ $row->invoice_number }} &mdash; {{ ucfirst($row->status) }} &mdash;
                    &#8377;{{ number_format((float) $row->total_amount, 2) }}
                    &mdash; <a class="lms-link" href="{{ route('invoices.show', ['subdomain' => $subdomain, 'invoice' => $row->id]) }}" target="_blank">View / print</a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
