<div>
    <h1 class="lms-heading">{{ $assignment->title }}</h1>
    <p class="lms-subtext">Due: {{ $assignment->due_at->format('Y-m-d H:i') }} &middot; Max marks: {{ (float) $assignment->max_marks }}</p>

    @if ($assignment->instructions)
        <p class="lms-subtext">{{ $assignment->instructions }}</p>
    @endif

    @error('submission')
        <p class="lms-error">{{ $message }}</p>
    @enderror

    @if ($history->isNotEmpty())
        <h3 class="lms-field-label">Your submissions</h3>
        <ol>
            @foreach ($history as $version)
                <li class="lms-list-item" wire:key="version-{{ $version->id }}">
                    <p>
                        <strong>Version {{ $version->version_number }}</strong>
                        <span class="lms-badge @if ($version->graded_at === null) lms-badge--muted @endif">{{ $version->graded_at !== null ? 'Graded' : 'Awaiting grading' }}</span>
                        @if ($version->is_late)
                            <span class="lms-badge lms-badge--muted">Late</span>
                        @endif
                    </p>
                    <p class="lms-meta">Submitted {{ $version->submitted_at->diffForHumans() }}</p>

                    <ul>
                        @foreach ($version->files as $file)
                            <li class="lms-meta" wire:key="version-{{ $version->id }}-file-{{ $loop->index }}">{{ $file['original_name'] }}</li>
                        @endforeach
                    </ul>

                    @if ($version->graded_at !== null)
                        <p>Marks: {{ (float) $version->marks_awarded }} / {{ (float) $assignment->max_marks }}</p>
                        @if ($version->feedback)
                            <p class="lms-meta">{{ $version->feedback }}</p>
                        @endif
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if ($canSubmitAnotherVersion)
        <label class="lms-field">
            <span class="lms-field-label">Upload files</span>
            <input class="lms-input" type="file" wire:model="files" multiple>
        </label>
        @error('files.*')
            <p class="lms-error">{{ $message }}</p>
        @enderror

        <button type="button" class="lms-button" wire:click="submit">{{ $history->isEmpty() ? 'Submit' : 'Resubmit' }}</button>
    @else
        <p class="lms-meta">You have used all 3 allowed submissions for this assignment.</p>
    @endif
</div>
