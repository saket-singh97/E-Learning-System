<div>
    <h2>My affiliate codes</h2>

    @if (! $canRespond)
        <p>Log in to see your affiliate dashboard.</p>
    @else
        @forelse ($codes as $row)
            <div style="border:1px solid #ddd; padding:1rem; margin-bottom:1rem;">
                <p><strong>{{ $row['code']->code }}</strong> ({{ $row['code']->commission_type }}, {{ $row['code']->commission_value }})</p>
                <p>Referrals: {{ $row['referral_count'] }}</p>
                <p>Confirmed commission: &#8377;{{ number_format($row['confirmed'], 2) }}</p>
                <p>Reversed commission: &#8377;{{ number_format($row['reversed'], 2) }}</p>
                <p>Available balance: &#8377;{{ number_format($row['balance'], 2) }}</p>

                <form wire:submit="requestPayout({{ $row['code']->id }})">
                    <input type="number" step="0.01" wire:model="requestAmount" placeholder="Amount">
                    @error('requestAmount')
                        <p>{{ $message }}</p>
                    @enderror
                    <button type="submit">Request payout</button>
                </form>

                @if ($row['code']->payoutRequests->isNotEmpty())
                    <ul>
                        @foreach ($row['code']->payoutRequests as $request)
                            <li>&#8377;{{ number_format((float) $request->amount, 2) }} — {{ ucfirst($request->status) }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <p>You don't own any affiliate codes yet.</p>
        @endforelse
    @endif
</div>
