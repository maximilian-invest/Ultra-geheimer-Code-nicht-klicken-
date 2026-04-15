<!-- resources/views/docs/partials/_showcase.blade.php -->
@php
    // Gallery: everything after the first image (which is already in the hero)
    $galleryImages = array_slice($heroImages ?? [], 1);
@endphp

@if (!empty($galleryImages) || !empty($showcase['description']) || !empty($showcase['location']) || !empty($showcase['facts']))
<section class="project-showcase">

    @if (!empty($galleryImages))
        <div class="showcase-gallery showcase-gallery-{{ min(count($galleryImages), 5) }}">
            @foreach ($galleryImages as $i => $img)
                <figure class="showcase-gallery-tile" style="animation-delay: {{ $i * 60 }}ms;">
                    <img src="{{ $img }}" alt="{{ $link->property->project_name ?? 'Projektbild' }} — Bild {{ $i + 2 }}" loading="lazy">
                </figure>
            @endforeach
        </div>
    @endif

    @if (!empty($showcase['facts']))
        <div class="showcase-facts">
            @foreach ($showcase['facts'] as $fact)
                <div class="fact-tile">
                    <div class="fact-icon">
                        @switch($fact['icon'])
                            @case('units')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                @break
                            @case('year')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                @break
                            @case('price')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                @break
                            @case('area')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                                @break
                            @case('location')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                @break
                            @case('energy')
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                @break
                            @default
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/></svg>
                        @endswitch
                    </div>
                    <div class="fact-meta">
                        <span class="fact-label">{{ $fact['label'] }}</span>
                        <span class="fact-value">{{ $fact['value'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if (!empty($showcase['description']) || !empty($showcase['location']))
        <div class="showcase-prose">
            @if (!empty($showcase['description']))
                <article class="showcase-prose-block">
                    <h2>Warum dieses Projekt?</h2>
                    <p>{!! nl2br(e($showcase['description'])) !!}</p>
                </article>
            @endif
            @if (!empty($showcase['location']))
                <article class="showcase-prose-block">
                    <h2>Lage &amp; Umgebung</h2>
                    <p>{!! nl2br(e($showcase['location'])) !!}</p>
                </article>
            @endif
            @if (!empty($showcase['equipment']))
                <article class="showcase-prose-block">
                    <h2>Ausstattung</h2>
                    <p>{!! nl2br(e($showcase['equipment'])) !!}</p>
                </article>
            @endif
        </div>
    @endif

</section>
@endif
