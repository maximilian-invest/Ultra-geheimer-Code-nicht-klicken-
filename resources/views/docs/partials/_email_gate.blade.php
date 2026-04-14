<!-- resources/views/docs/partials/_email_gate.blade.php -->
<section class="hero">
    @if (!empty($link->property->title_image_url))
        <img src="{{ $link->property->title_image_url }}" alt="">
    @endif
    <div class="hero-text">
        <h1>{{ $link->property->project_name ?? 'Ihre Unterlagen' }}</h1>
        <div class="meta">{{ $link->property->address ?? '' }} · {{ $link->property->city ?? '' }}</div>
    </div>
</section>

<article class="email-gate-card">
    <h2>Unterlagen ansehen</h2>
    <p>Bitte bestaetigen Sie Ihre Email-Adresse, um die Unterlagen einzusehen.</p>

    <form method="POST" action="/docs/{{ $link->token }}/unlock">
        @csrf
        <div class="form-field">
            <input type="email" name="email" class="underline-input" placeholder="ihre@email.at" required>
        </div>

        <label class="dsgvo-check">
            <input type="checkbox" name="dsgvo" required>
            <span>Ich stimme zu, dass meine Email-Adresse im Rahmen der Betreuung dieses Immobilien-Projekts verarbeitet wird. Details in der <a href="/datenschutz">Datenschutzerklaerung</a>.</span>
        </label>

        <button type="submit" class="cta-primary">Unterlagen ansehen →</button>
    </form>
</article>
