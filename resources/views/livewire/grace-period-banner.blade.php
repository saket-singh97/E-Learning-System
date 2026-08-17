<div>
    @if ($daysLeft !== null)
        <div style="background:#fef3c7;color:#92400e;padding:0.5rem 1rem;text-align:center;font-size:0.875rem;">
            Payment failed — your institute has
            <strong>{{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }}</strong>
            left in its grace period before access is suspended. Update your billing details to avoid interruption.
        </div>
    @endif
</div>
