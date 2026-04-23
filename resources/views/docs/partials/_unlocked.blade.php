<!-- resources/views/docs/partials/_unlocked.blade.php -->
<section class="unlocked-section">
    <div class="unlocked-heading">
        <h2>Ihre Unterlagen</h2>
        <p>Alle Dokumente zu diesem Projekt. Klicken Sie auf "Ansehen", um ein Dokument im Browser zu öffnen.</p>
    </div>

    @if ($expose ?? null)
        <div style="background: linear-gradient(90deg, #fff7ed 0%, #fff 50%); padding: 18px 20px; border: 1px solid #ffe5c7; border-radius: 8px; margin-bottom: 18px; display: flex; align-items: center; gap: 14px;">
            <div style="width:42px;height:42px;border-radius:8px;background:linear-gradient(135deg,#ee7600,#c95b00);color:#fff;display:flex;align-items:center;justify-content:center;font-family:Georgia,serif;font-weight:600;font-size:16px;flex-shrink:0;">SR</div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:14px;color:#1a1a1a;font-weight:600;">Exposé ansehen</div>
                <div style="font-size:12px;color:#888;margin-top:2px;">Das vollständige Objektexposé im Browser</div>
            </div>
            <a href="{{ $expose['view_url'] }}" target="_blank" style="background:#ee7600;color:#fff;padding:8px 14px;border-radius:4px;font-size:12px;font-weight:600;text-decoration:none;">Öffnen</a>
            <a href="{{ $expose['download_url'] }}" style="padding:8px 14px;border:1px solid #e5e7eb;border-radius:4px;font-size:12px;font-weight:600;color:#333;text-decoration:none;">PDF</a>
        </div>
    @endif

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
