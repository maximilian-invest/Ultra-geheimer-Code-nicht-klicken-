<!-- resources/views/docs/partials/_unlocked.blade.php -->
<section class="unlocked-section">
    <div class="unlocked-heading">
        <h2>Ihre Unterlagen</h2>
        <p>Alle Dokumente zu diesem Projekt. Klicken Sie auf "Ansehen", um ein Dokument im Browser zu öffnen.</p>
    </div>

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
</section>

<div id="viewer-root"></div>
<script src="{{ asset('docs/docs.js') }}"></script>
