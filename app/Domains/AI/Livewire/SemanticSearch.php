<?php

namespace App\Domains\AI\Livewire;

use App\Domains\AI\Models\LessonArtifactChunk;
use App\Domains\AI\Services\SemanticSearchService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * L12 pt.2 — search UI over SemanticSearchService (B5). Wired into the
 * student portal dashboard (existing embed point — no new page).
 */
class SemanticSearch extends Component
{
    public string $query = '';

    /** @var Collection<int, LessonArtifactChunk> */
    public Collection $results;

    public function mount(): void
    {
        $this->results = collect();
    }

    /**
     * Debounced client-side (the view's own wire:model.live.debounce) — no
     * separate submit button, results update as the viewer types. An empty
     * query, or no authenticated viewer, short-circuits before ever calling
     * SemanticSearchService — no wasted AiProvider::embed() call.
     */
    public function updatedQuery(): void
    {
        $viewer = auth()->user();

        if (trim($this->query) === '' || $viewer === null) {
            $this->results = collect();

            return;
        }

        $this->results = app(SemanticSearchService::class)->search(
            $this->query,
            $viewer,
            app(CurrentTenant::class)->id(),
        );
    }

    /**
     * Same `{lesson}#{start_s}` timestamp-anchor shape DoubtChatService's
     * own citations already point at, just as a real navigable URL here
     * (a search result may link to a different lesson than whichever one
     * the viewer is currently watching, unlike DoubtChat's own same-page
     * seek-to dispatch).
     */
    public function watchUrlFor(LessonArtifactChunk $chunk): string
    {
        return route('lessons.watch', [
            'subdomain' => app(CurrentTenant::class)->get()->subdomain,
            'lesson' => $chunk->lessonArtifact->lesson_id,
        ]).'#t='.$chunk->start_s;
    }

    public function render(): View
    {
        return view('ai::semantic-search');
    }
}
