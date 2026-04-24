@php
    $b = $ctx->broker;
    // Makler-Signatur hat Vorrang vor User-Basis-Feldern — der Makler pflegt
    // dort bewusst gewählte Exposé-Daten (oft andere Mail als der Login-Account).
    $name    = $b?->signature_name  ?: $b?->name  ?: 'SR Homes';
    $role    = $b?->signature_title ?: 'Immobilienmakler';
    $phone   = $b?->signature_phone ?: $b?->phone ?: null;
    $mail    = $b?->signature_email ?: $b?->email ?: null;
    $web     = $b?->signature_website ?: 'www.sr-homes.at';
    $company = $b?->signature_company ?: 'SR Homes';
    $initials = collect(preg_split('/\s+/', $name))->map(fn($x) => mb_substr($x, 0, 1))->take(2)->implode('');
    // Portrait kommt aus admin_settings.signature_photo_path (im
    // Einstellungen-Tab unter „Portrait hochladen"). Fallback auf
    // users.profile_image, falls Legacy-Daten vorhanden sind.
    $photoPath = trim((string) ($ctx->brokerPhotoPath ?? $b?->profile_image ?? ''));
    $photoUrl  = $photoPath !== '' ? asset('storage/' . $photoPath) : null;

    $disclaimer = 'Dieses Exposé wurde mit größter Sorgfalt erstellt und dient ausschließlich der unverbindlichen Information. Alle Angaben zu Flächen, Maßen, Preisen, Erträgen sowie sonstigen Daten beruhen auf den Informationen und Unterlagen des Eigentümers bzw. Dritter. Für deren Richtigkeit, Vollständigkeit und Aktualität wird keine Haftung übernommen. Das Exposé stellt kein verbindliches Angebot dar. Änderungen, Irrtümer und Zwischenverkauf bleiben ausdrücklich vorbehalten. Maßgeblich sind ausschließlich die im Kaufvertrag vereinbarten Inhalte. Dieses Dokument ist vertraulich zu behandeln und darf ohne unsere ausdrückliche Zustimmung weder vervielfältigt noch an Dritte weitergegeben werden.';
@endphp

<style>
  .kontakt-page .grid {
    position: absolute; top: 124px; left: 48px; right: 48px; bottom: 28px;
    display: grid; grid-template-columns: 1fr 1fr; gap: 40px;
  }
  .kontakt-page .gh {
    font-size: 12px; color: var(--accent); letter-spacing: 2.5px; text-transform: uppercase;
    font-weight: 700; padding-bottom: 6px; margin-bottom: 10px;
    border-bottom: 1px solid var(--border);
  }
  .kontakt-page .contact-box {
    display: flex; gap: 16px; padding: 16px 18px;
    background: #fafafa; border-radius: 4px; margin-bottom: 18px;
  }
  .kontakt-page .avatar {
    width: 72px; height: 72px; border-radius: 50%;
    background: linear-gradient(135deg, #ee7600, #c95b00);
    color: #fff; font-family: Georgia, serif; font-size: 26px;
    display: flex; align-items: center; justify-content: center; font-weight: 600;
    flex-shrink: 0;
    overflow: hidden;
  }
  .kontakt-page .avatar img {
    width: 100%; height: 100%; object-fit: cover; display: block;
  }
  .kontakt-page .info .name { font-family: Georgia, serif; font-size: 18px; color: var(--text-primary); margin-bottom: 2px; }
  .kontakt-page .info .role { font-size: 11px; color: #999; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 10px; }
  .kontakt-page .info .line { font-size: 13px; color: #333; padding: 2px 0; }
  .kontakt-page .info .line .k { color: #999; display: inline-block; width: 68px; }
  .kontakt-page .over { font-size: 13px; line-height: 1.55; color: #444; margin-top: 6px; }
  .kontakt-page .r {
    display: flex; justify-content: space-between;
    padding: 5px 0; border-bottom: 1px dotted #f0f0f0; font-size: 13px; gap: 12px;
  }
  .kontakt-page .r:last-child { border-bottom: none; }
  .kontakt-page .r .k { color: var(--text-secondary); }
  .kontakt-page .r .v { font-family: Georgia, serif; color: var(--text-primary); font-size: 14px; }
  .kontakt-page .r .v.accent { color: var(--accent); font-weight: 700; }
  .kontakt-page .disclaimer {
    margin-top: 16px; padding: 14px 16px;
    background: #fafafa; border-left: 3px solid var(--accent); border-radius: 2px;
    font-size: 9.5px; line-height: 1.55; color: #555;
  }
  .kontakt-page .disclaimer .dh {
    font-size: 10px; color: var(--accent); letter-spacing: 2px;
    text-transform: uppercase; font-weight: 700; margin-bottom: 5px;
  }
</style>

<div class="page kontakt-page">
    <div class="pn">{{ $pageNum }}</div>
    <div class="title-s">Kontakt</div>
    <div class="aline"></div>
    <div class="grid">
        <div>
            <div class="gh">Ihr Ansprechpartner</div>
            <div class="contact-box">
                <div class="avatar">
                    @if ($photoUrl)
                        <img src="{{ $photoUrl }}" alt="{{ $name }}">
                    @else
                        {{ $initials }}
                    @endif
                </div>
                <div class="info">
                    <div class="name">{{ $name }}</div>
                    <div class="role">{{ $role }}</div>
                    @if ($phone)
                        <div class="line"><span class="k">Telefon</span>{{ $phone }}</div>
                    @endif
                    @if ($mail)
                        <div class="line"><span class="k">E-Mail</span>{{ $mail }}</div>
                    @endif
                    @if ($web)
                        <div class="line"><span class="k">Web</span>{{ $web }}</div>
                    @endif
                </div>
            </div>
            <div class="gh" style="margin-top: 4px;">Über {{ $company }}</div>
            <p class="over">{{ $company }} begleitet Sie mit Erfahrung und regionaler Expertise durch den gesamten Verkaufs- und Kaufprozess — von der Erstbesichtigung bis zur Schlüsselübergabe.</p>
        </div>
        <div>
            <div class="gh">Kaufnebenkosten</div>
            <div class="r"><span class="k">Grunderwerbsteuer</span><span class="v">3,5 %</span></div>
            <div class="r"><span class="k">Grundbucheintragung</span><span class="v">1,1 %</span></div>
            <div class="r"><span class="k">Vertragserrichtung</span><span class="v">1,5 %</span></div>
            <div class="r"><span class="k">Pfandrechtseintrag</span><span class="v">1,2 %</span></div>
            <div class="r"><span class="k">Käuferprovision</span><span class="v accent">3,0 % + USt</span></div>

            <div class="disclaimer">
                <div class="dh">Haftungsausschluss</div>
                {{ $disclaimer }}
            </div>
        </div>
    </div>
</div>
