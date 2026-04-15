<!-- resources/views/docs/partials/_footer.blade.php -->
<footer class="docs-footer">
    <div class="docs-footer-inner">
        <div class="docs-footer-brand">
            <img class="docs-footer-logo" src="{{ asset('assets/logo-full-white.svg') }}" alt="{{ $company['name'] ?? 'SR-Homes' }}">
            <p class="docs-footer-tagline">Ihr Partner für Immobilien in Salzburg, Oberösterreich und Tirol.</p>
        </div>

        <div class="docs-footer-col">
            <h4>Kontakt</h4>
            @if (!empty($company['name']))
                <p>{{ $company['name'] }}</p>
            @endif
            @if (!empty($company['address']))
                <p>{{ $company['address'] }}</p>
            @endif
            @if (!empty($company['phone']))
                <p><a href="tel:{{ preg_replace('/\s+/', '', $company['phone']) }}">{{ $company['phone'] }}</a></p>
            @endif
            @if (!empty($company['fn']))
                <p class="docs-footer-small">{{ $company['fn'] }}</p>
            @endif
        </div>

        <div class="docs-footer-col">
            <h4>Mehr erfahren</h4>
            @if (!empty($company['web']))
                <p><a href="https://{{ $company['web'] }}" target="_blank" rel="noopener">{{ $company['web'] }}</a></p>
            @endif
            <p><a href="https://{{ $company['web'] ?? 'sr-homes.at' }}/impressum" target="_blank" rel="noopener">Impressum</a></p>
            <p><a href="https://{{ $company['web'] ?? 'sr-homes.at' }}/datenschutz" target="_blank" rel="noopener">Datenschutz</a></p>
        </div>
    </div>
    <div class="docs-footer-bottom">
        <span>&copy; {{ date('Y') }} {{ $company['name'] ?? 'SR-Homes Immobilien GmbH' }}</span>
    </div>
</footer>
