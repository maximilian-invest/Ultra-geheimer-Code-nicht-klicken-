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
<main class="docs-container">
    @if ($state === 'locked')
        @include('docs.partials._email_gate', ['link' => $link])
    @else
        @include('docs.partials._unlocked', ['link' => $link, 'files' => $files, 'session' => $session])
    @endif
</main>
</body>
</html>
