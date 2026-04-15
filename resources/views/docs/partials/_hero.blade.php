<!-- resources/views/docs/partials/_hero.blade.php -->
<section class="hero">
    @if (!empty($heroImages))
        <img src="{{ $heroImages[0] }}" alt="{{ $link->property->project_name ?? 'Projektbild' }}">
    @endif
    <div class="hero-text">
        <h1>{{ $link->property->project_name ?? 'Ihre Unterlagen' }}</h1>
        <div class="meta">
            @if ($state === 'unlocked' && !empty($files) && !empty($session))
                {{ count($files) }} Dokument(e) · angesehen als {{ $session->email }}
            @else
                {{ $link->property->address ?? '' }}@if (!empty($link->property->address) && !empty($link->property->city)) · @endif{{ $link->property->city ?? '' }}
            @endif
        </div>
        @if (!empty($showcase['badges']))
            <ul class="hero-badges">
                @foreach ($showcase['badges'] as $badge)
                    <li>{{ $badge['label'] }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
