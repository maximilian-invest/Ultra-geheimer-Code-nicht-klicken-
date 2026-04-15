<!-- resources/views/docs/partials/_email_gate.blade.php -->
<article class="email-gate-card">
    <h2>Unterlagen freischalten</h2>
    <p>Exposé, Grundrisse, Preisliste und weitere Unterlagen — direkt im Browser einsehen. Keine Downloads, keine Weiterleitung.</p>

    <form method="POST" action="/docs/{{ $link->token }}/unlock">
        @csrf
        <div class="form-field">
            <input type="email" name="email" class="underline-input" placeholder="ihre@email.at" required>
        </div>

        <label class="dsgvo-check">
            <input type="checkbox" name="dsgvo" required>
            <span>Ich stimme zu, dass meine Email-Adresse im Rahmen der Betreuung dieses Immobilien-Projekts verarbeitet wird. Details in der <a href="/datenschutz">Datenschutzerklaerung</a>.</span>
        </label>

        <button type="submit" class="cta-primary">Unterlagen freischalten →</button>
    </form>
</article>
