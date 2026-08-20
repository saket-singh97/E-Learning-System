<div>
    <h1>Course catalog</h1>

    @auth
        <a href="{{ route('wishlist.index', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain]) }}">My wishlist</a>
    @endauth

    <form>
        <label>
            Search
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search courses">
        </label>

        <label>
            Category
            <select wire:model.live="categoryId">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </label>

        <label>
            Tag
            <select wire:model.live="tagId">
                <option value="">All tags</option>
                @foreach ($tags as $tag)
                    <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                @endforeach
            </select>
        </label>
    </form>

    <ul>
        @forelse ($courses as $course)
            <li>
                <a href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $course->slug]) }}">
                    {{ $course->title }}
                </a>
                <button type="button" wire:click="toggleWishlist({{ $course->id }})">
                    {{ in_array($course->id, $wishlistedCourseIds, true) ? 'Remove from wishlist' : 'Add to wishlist' }}
                </button>
            </li>
        @empty
            <li>No courses match these filters.</li>
        @endforelse
    </ul>

    @if ($recommendations->isNotEmpty())
        <h2>Recommended for you</h2>
        <ul>
            @foreach ($recommendations as $course)
                <li>
                    <a href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $course->slug]) }}">
                        {{ $course->title }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
