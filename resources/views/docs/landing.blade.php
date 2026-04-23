<!-- resources/views/docs/landing.blade.php -->
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $link->property->project_name ?? 'Wohnprojekt' }} · Unterlagen</title>
    <link rel="stylesheet" href="{{ asset('docs/docs.css') }}">
</head>
<body>
@include('docs.partials._header', ['company' => $company ?? []])

<main class="docs-container">
    @include('docs.partials._hero', [
        'link' => $link,
        'heroImages' => $heroImages ?? [],
        'showcase' => $showcase ?? null,
        'state' => $state,
        'files' => $files ?? null,
        'session' => $session ?? null,
    ])

    @if ($state === 'locked')
        {{-- Locked: request email first so access is clearly gated from the start --}}
        @include('docs.partials._email_gate', ['link' => $link])
        @include('docs.partials._showcase', [
            'link' => $link,
            'heroImages' => $heroImages ?? [],
            'showcase' => $showcase ?? [],
        ])
    @else
        {{-- Unlocked: documents first (primary reason the customer is here), then showcase as context --}}
        @include('docs.partials._unlocked', [
            'link' => $link,
            'files' => $files,
            'session' => $session,
            'expose' => $expose ?? null,
        ])
        @include('docs.partials._showcase', [
            'link' => $link,
            'heroImages' => $heroImages ?? [],
            'showcase' => $showcase ?? [],
        ])
    @endif
</main>

@include('docs.partials._footer', ['company' => $company ?? []])
</body>
</html>
