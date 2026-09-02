<div>
    <h1 class="lms-heading">Affiliate dashboard</h1>

    @if (! $canRespond)
        <p class="lms-subtext">Log in to see your affiliate dashboard.</p>
    @else
        <p class="lms-subtext">Share your referral link and track commissions across every code you own.</p>

        @forelse ($codes as $row)
            <div class="lms-panel">
                <h2 class="lms-field-label">{{ $row['code']->code }}</h2>
                <p class="lms-meta">
                    {{ ucfirst($row['code']->commission_type) }} commission &middot;
                    {{ $row['code']->commission_value }}{{ $row['code']->commission_type === 'percentage' ? '%' : '' }}
                </p>

                <label class="lms-field">
                    <span class="lms-field-label">Referral link</span>
                    <input
                        class="lms-input"
                        type="text"
                        id="referral-link-{{ $row['code']->id }}"
                        value="{{ url('/') }}/?ref={{ $row['code']->code }}"
                        readonly
                        onclick="this.select()"
                    >
                </label>
                <button
                    type="button"
                    class="lms-button-secondary"
                    style="width:auto;"
                    onclick="const el = document.getElementById('referral-link-{{ $row['code']->id }}'); el.select(); navigator.clipboard.writeText(el.value);"
                >Copy</button>

                <p class="lms-meta" style="margin-top:0.75rem;">
                    Referrals: {{ $row['referral_count'] }}
                    &middot; Confirmed: &#8377;{{ number_format($row['confirmed'], 2) }}
                    &middot; Reversed: &#8377;{{ number_format($row['reversed'], 2) }}
                </p>
                <p class="lms-field-label">Available balance: &#8377;{{ number_format($row['balance'], 2) }}</p>

                <form wire:submit="requestPayout({{ $row['code']->id }})">
                    <label class="lms-field">
                        <span class="lms-field-label">Request payout</span>
                        <input class="lms-input" type="number" step="0.01" wire:model="requestAmount" placeholder="Amount">
                    </label>
                    @error('requestAmount')
                        <p class="lms-error">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="lms-button lms-button-secondary" style="width:auto;">Request payout</button>
                </form>

                @if ($row['code']->payoutRequests->isNotEmpty())
                    <div style="margin-top:1rem;">
                        @foreach ($row['code']->payoutRequests as $request)
                            <div class="lms-list-item">
                                &#8377;{{ number_format((float) $request->amount, 2) }}
                                <span class="lms-meta">— {{ ucfirst($request->status) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <p class="lms-subtext">You don't own any affiliate codes yet.</p>
        @endforelse
    @endif
</div>
