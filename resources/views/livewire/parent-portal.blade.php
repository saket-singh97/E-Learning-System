<div>
    @auth
        <h1>Welcome back, {{ auth()->user()->name }}</h1>

        @if ($linkedStudents->isEmpty())
            <p>No students are linked to your account yet.</p>
        @else
            @if ($linkedStudents->count() > 1)
                <nav>
                    @foreach ($linkedStudents as $student)
                        <button
                            type="button"
                            wire:click="selectStudent({{ $student->id }})"
                            @disabled($selectedStudent && $selectedStudent->id === $student->id)
                        >{{ $student->name }}</button>
                    @endforeach
                </nav>
            @endif

            @if ($selectedStudent && $metrics)
                <section wire:key="student-{{ $selectedStudent->id }}">
                    <h2>{{ $selectedStudent->name }}</h2>

                    <dl>
                        <dt>Attendance</dt>
                        <dd>{{ $metrics['attendance_percent'] !== null ? number_format($metrics['attendance_percent'], 1).'%' : 'No attendance recorded yet' }}</dd>

                        <dt>Video coverage</dt>
                        <dd>{{ $metrics['coverage_percent'] !== null ? number_format($metrics['coverage_percent'], 1).'%' : 'No lessons watched yet' }}</dd>

                        <dt>Quiz average</dt>
                        <dd>{{ $metrics['quiz_average_percent'] !== null ? number_format($metrics['quiz_average_percent'], 1).'%' : 'No quizzes attempted yet' }}</dd>

                        <dt>Fee status</dt>
                        <dd>{{ ucwords(str_replace('_', ' ', $metrics['fee_status'])) }}</dd>
                    </dl>

                    @if ($syllabusByCourse->isNotEmpty())
                        <h3>Syllabus coverage</h3>
                        @foreach ($syllabusByCourse as $courseTitle => $tracker)
                            <h4>{{ $courseTitle }}</h4>
                            @include('content::syllabus-tracker', ['tracker' => $tracker])
                        @endforeach
                    @endif

                    @if ($metrics['outstanding_invoices']->isNotEmpty())
                        <h3>Outstanding invoices</h3>
                        <ul>
                            @foreach ($metrics['outstanding_invoices'] as $invoice)
                                <li>
                                    {{ $invoice->invoice_number }} —
                                    ₹{{ number_format((float) $invoice->total_amount, 2) }} —
                                    due {{ $invoice->due_date->format('Y-m-d') }} —
                                    {{ ucfirst($invoice->status) }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        @endif

        <button type="button" wire:click="logout">Log out</button>
    @elseif ($twoFactorUserId)
        <form wire:submit="verifyTwoFactorCode">
            <label>
                Two-factor code
                <input type="text" wire:model="twoFactorCode" autocomplete="one-time-code">
            </label>
            @error('twoFactorCode')
                <p>{{ $message }}</p>
            @enderror

            <button type="submit">Verify</button>
        </form>
    @else
        <form wire:submit="login">
            <label>
                Email
                <input type="email" wire:model="email">
            </label>
            @error('email')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Password
                <input type="password" wire:model="password">
            </label>

            <button type="submit">Log in</button>
        </form>

        <a href="{{ route('auth.google.redirect', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'portal' => 'parent']) }}">Sign in with Google</a>
    @endauth
</div>
