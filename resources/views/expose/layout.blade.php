<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=1200">
    <title>Exposé · {{ $ctx->property->title ?? $ctx->property->address }}</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    @include('expose.styles')
</head>
<body>
    @php($allowedTypes = ['cover', 'details', 'haus', 'lage', 'impressionen_intro', 'impressionen', 'kontakt'])
    @foreach ($ctx->pages as $i => $page)
        @continue(!in_array($page['type'] ?? '', $allowedTypes, true))
        @php($pageNum = sprintf('%02d / %02d', $i + 1, count($ctx->pages)))
        @include("expose.pages.{$page['type']}", ['page' => $page, 'pageNum' => $pageNum, 'ctx' => $ctx])
    @endforeach

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    @stack('scripts')
</body>
</html>
