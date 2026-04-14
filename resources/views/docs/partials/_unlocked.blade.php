<!-- resources/views/docs/partials/_unlocked.blade.php -->
<section class="hero" style="height: 360px;">
    @if (!empty($heroImages))
        <img src="{{ $heroImages[0] }}" alt="{{ $link->property->project_name ?? 'Projektbild' }}">
    @endif
    <div class="hero-text">
        <h1>{{ $link->property->project_name ?? 'Ihre Unterlagen' }}</h1>
        <div class="meta">{{ count($files) }} Dokument(e) · angesehen als {{ $session->email }}</div>
    </div>
</section>

<section class="unlocked-grid">
    @foreach ($files as $i => $file)
        <article class="doc-card" style="animation-delay: {{ $i * 80 }}ms;">
            <h3>{{ $file->label ?: $file->filename }}</h3>
            <div class="file-size">{{ $file->file_size ? number_format($file->file_size / 1024 / 1024, 1) . ' MB' : 'PDF' }}</div>
            <div class="actions">
                <button type="button" class="btn-view" data-file-id="{{ $file->id }}" data-file-name="{{ $file->filename }}">
                    Ansehen
                </button>
                <a class="btn-download" href="/docs/{{ $link->token }}/file/{{ $file->id }}/download">
                    Download
                </a>
            </div>
        </article>
    @endforeach
</section>

<div id="viewer-root"></div>
<script src="{{ asset('docs/docs.js') }}"></script>
