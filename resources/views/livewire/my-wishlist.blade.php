<div>
    <h1>My wishlist</h1>

    @if (! $viewer)
        <p>Log in to see your wishlist.</p>
    @else
        <ul>
            @forelse ($courses as $course)
                <li>
                    <a href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $course->slug]) }}">
                        {{ $course->title }}
                    </a>
                    <button type="button" wire:click="toggleWishlist({{ $course->id }})">Remove from wishlist</button>
                </li>
            @empty
                <li>Your wishlist is empty.</li>
            @endforelse
        </ul>
    @endif
</div>
