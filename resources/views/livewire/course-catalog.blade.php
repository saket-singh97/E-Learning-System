<div>
    <h1 class="lms-heading">Course catalog</h1>

    @auth
        <a class="lms-link" style="margin-top:0" href="{{ route('wishlist.index', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain]) }}">My wishlist</a>
    @endauth

    <form class="lms-panel">
        <label class="lms-field">
            <span class="lms-field-label">Search</span>
            <input class="lms-input" type="search" wire:model.live.debounce.300ms="search" placeholder="Search courses">
        </label>

        <label class="lms-field">
            <span class="lms-field-label">Category</span>
            <select class="lms-input" wire:model.live="categoryId">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="lms-field">
            <span class="lms-field-label">Tag</span>
            <select class="lms-input" wire:model.live="tagId">
                <option value="">All tags</option>
                @foreach ($tags as $tag)
                    <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="lms-field" style="margin-bottom:0">
            <span class="lms-field-label">Language</span>
            <select class="lms-input" wire:model.live="language">
                <option value="">All languages</option>
                @foreach ($languages as $languageCode)
                    <option value="{{ $languageCode }}">{{ ['en' => 'English', 'hi' => 'Hindi'][$languageCode] ?? $languageCode }}</option>
                @endforeach
            </select>
        </label>
    </form>

    @forelse ($courses as $course)
        <div class="lms-panel">
            <a class="lms-link" style="margin-top:0" href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $course->slug]) }}">
                {{ $course->title }}
            </a>
            <div>
                <button type="button" class="lms-button lms-button-secondary" style="width:auto" wire:click="toggleWishlist({{ $course->id }})">
                    {{ in_array($course->id, $wishlistedCourseIds, true) ? 'Remove from wishlist' : 'Add to wishlist' }}
                </button>
            </div>
        </div>
    @empty
        <p class="lms-meta">No courses match these filters.</p>
    @endforelse

    @if ($recommendations->isNotEmpty())
        <h2 class="lms-heading" style="margin-top:2rem">Recommended for you</h2>
        @foreach ($recommendations as $course)
            <div class="lms-panel">
                <a class="lms-link" style="margin-top:0" href="{{ route('courses.show', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'course' => $course->slug]) }}">
                    {{ $course->title }}
                </a>
            </div>
        @endforeach
    @endif
</div>
