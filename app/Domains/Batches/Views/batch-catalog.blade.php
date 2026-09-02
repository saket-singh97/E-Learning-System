<div>
    <h1 class="lms-heading">Batches</h1>

    @if ($rows->isEmpty())
        <p class="lms-subtext">No batches open right now.</p>
    @else
        <div class="lms-panel">
            @foreach ($rows as $row)
                @php($batch = $row['batch'])
                <div class="lms-list-item" wire:key="batch-{{ $batch->id }}">
                    <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                        <div>
                            <p class="lms-field-label" style="margin:0;">{{ $batch->title }}</p>
                            <p class="lms-meta">{{ $batch->starts_on->format('Y-m-d') }} &ndash; {{ $batch->ends_on->format('Y-m-d') }}</p>
                        </div>
                        <div style="text-align:right;">
                            <p class="lms-meta">{{ $batch->is_paid ? '₹' . number_format((float) $batch->price, 2) : 'Free' }}</p>
                            <p class="lms-meta">{{ $row['seatsRemaining'] === null ? 'Unlimited seats' : $row['seatsRemaining'].' seats open' }}</p>
                        </div>
                    </div>

                    <div style="margin-top:0.5rem;">
                        @if ($row['enrolment'])
                            @if ($row['enrolment']->status === 'waitlisted')
                                <span class="lms-badge lms-badge--muted">Waitlisted &mdash; position {{ $row['waitlistPosition'] }}</span>
                            @elseif ($row['enrolment']->status === 'pending_approval')
                                <span class="lms-badge lms-badge--muted">Request pending</span>
                            @elseif ($row['enrolment']->status === 'pending_payment')
                                <livewire:fee-checkout :enrolment="$row['enrolment']" :key="'checkout-'.$row['enrolment']->id" />
                            @else
                                <span class="lms-badge">{{ ucfirst($row['enrolment']->status) }}</span>
                            @endif
                        @elseif (! auth()->check())
                            <a class="lms-link" href="{{ route('student.dashboard', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain]) }}">Log in to join</a>
                        @elseif ($batch->self_enrol_mode === 'self_enrol_direct')
                            <button type="button" class="lms-button" style="width:auto;" wire:click="join({{ $batch->id }})">Join</button>
                        @elseif ($batch->self_enrol_mode === 'self_enrol_approval')
                            <button type="button" class="lms-button lms-button-secondary" style="width:auto;" wire:click="join({{ $batch->id }})">Request to join</button>
                        @else
                            <span class="lms-meta">Contact your institute to enrol</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
