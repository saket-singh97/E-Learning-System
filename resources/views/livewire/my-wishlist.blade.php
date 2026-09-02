<div>
    <h1 class="lms-heading">My wishlist</h1>

    @if (! $viewer)
        <p class="lms-meta">Log in to see your wishlist.</p>
    @else
        <div class="lms-panel">
            <ul>
                @forelse ($courses as $course)
                    <li class="lms-list-item">
                        <a class="lms-link" style="margin-top:0" href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $course->slug]) }}">
                            {{ $course->title }}
                        </a>
                        <div>
                            <button type="button" class="lms-button lms-button-secondary" style="width:auto" wire:click="toggleWishlist({{ $course->id }})">Remove from wishlist</button>
                        </div>
                    </li>
                @empty
                    <li class="lms-meta">Your wishlist is empty.</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
