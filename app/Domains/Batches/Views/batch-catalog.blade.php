<div>
    <h1>Batches</h1>

    @if ($rows->isEmpty())
        <p>No batches open right now.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Batch</th>
                    <th>Dates</th>
                    <th>Price</th>
                    <th>Seats</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php($batch = $row['batch'])
                    <tr wire:key="batch-{{ $batch->id }}">
                        <td>{{ $batch->title }}</td>
                        <td>{{ $batch->starts_on->format('Y-m-d') }} &ndash; {{ $batch->ends_on->format('Y-m-d') }}</td>
                        <td>{{ $batch->is_paid ? number_format((float) $batch->price, 2) : 'Free' }}</td>
                        <td>{{ $row['seatsRemaining'] === null ? 'Unlimited' : $row['seatsRemaining'].' open' }}</td>
                        <td>
                            @if ($row['enrolment'])
                                @if ($row['enrolment']->status === 'waitlisted')
                                    Waitlisted &mdash; position {{ $row['waitlistPosition'] }}
                                @elseif ($row['enrolment']->status === 'pending_approval')
                                    Request pending
                                @elseif ($row['enrolment']->status === 'pending_payment')
                                    <livewire:fee-checkout :enrolment="$row['enrolment']" :key="'checkout-'.$row['enrolment']->id" />
                                @else
                                    {{ ucfirst($row['enrolment']->status) }}
                                @endif
                            @elseif (! auth()->check())
                                <a href="{{ route('student.dashboard', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain]) }}">Log in to join</a>
                            @elseif ($batch->self_enrol_mode === 'self_enrol_direct')
                                <button type="button" wire:click="join({{ $batch->id }})">Join</button>
                            @elseif ($batch->self_enrol_mode === 'self_enrol_approval')
                                <button type="button" wire:click="join({{ $batch->id }})">Request to join</button>
                            @else
                                Contact your institute to enrol
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
