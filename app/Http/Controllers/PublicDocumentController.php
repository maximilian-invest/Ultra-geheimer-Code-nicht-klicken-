<?php

namespace App\Http\Controllers;

use App\Models\PropertyLink;
use App\Models\PropertyLinkSession;
use App\Services\LinkActivityLogger;
use App\Services\PropertyLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PublicDocumentController extends Controller
{
    public function __construct(
        protected PropertyLinkService $service,
        protected LinkActivityLogger $logger,
    ) {
    }

    public function show(Request $request, string $token): Response
    {
        $link = PropertyLink::where('token', $token)->first();

        if (!$link) {
            return response()->view('docs.error', ['reason' => 'not_found'], 404);
        }

        if ($link->revoked_at) {
            return response()->view('docs.error', ['reason' => 'revoked', 'link' => $link], 410);
        }

        if ($link->expires_at && $link->expires_at->isPast()) {
            return response()->view('docs.error', ['reason' => 'expired', 'link' => $link], 410);
        }

        // Check session cookie
        $session = $this->resolveSessionFromCookie($request, $link);

        $link->load('property');

        // Build property image gallery (first element = hero image)
        $heroImages = $this->resolvePropertyImages($link->property_id);

        // Build showcase data (key facts, descriptions) from property row
        $showcase = $this->buildShowcase($link->property);

        // Company info for header + footer
        $company = config('portal.company', []);

        $commonProps = [
            'link' => $link,
            'heroImages' => $heroImages,
            'showcase' => $showcase,
            'company' => $company,
        ];

        if ($session) {
            $files = DB::table('property_files')
                ->whereIn('id', $link->documentIds())
                ->get();

            return response()->view('docs.landing', array_merge($commonProps, [
                'session' => $session,
                'files' => $files,
                'state' => 'unlocked',
            ]));
        }

        return response()->view('docs.landing', array_merge($commonProps, [
            'state' => 'locked',
        ]));
    }

    /**
     * Collect property image URLs for the docs hero and gallery.
     *
     * Returns an array of absolute URLs (first element = hero image).
     * Pulls from property_images (the authoritative image table where the
     * admin UI actually uploads photos — floor plans, interior visuals,
     * and the iPhone exterior shots all live here). The old code queried
     * property_files which only contained 3 interior visualizations that
     * happened to be imported alongside the PDF documents, so every
     * exterior photo uploaded through the admin was invisible on the
     * docs landing page.
     *
     * Ordering: title image first (is_title_image = 1), then sort_order
     * ascending, then id ascending. Non-public and floor-plan entries
     * are excluded from the docs gallery.
     */
    protected function resolvePropertyImages(int $propertyId): array
    {
        $images = DB::table('property_images')
            ->where('property_id', $propertyId)
            ->where('is_public', 1)
            ->where('is_floorplan', 0)
            ->orderByDesc('is_title_image')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(6)
            ->get(['path']);

        $urls = [];
        foreach ($images as $img) {
            if (empty($img->path)) {
                continue;
            }
            $urls[] = asset('storage/' . ltrim($img->path, '/'));
        }

        return $urls;
    }

    /**
     * Build the project showcase payload from a Property model.
     *
     * Returns an associative array with:
     *  - badges:       list of ['label' => string] for hero sub-badges
     *  - description:  realty_description (stripped of HTML, trimmed)
     *  - location:     location_description (stripped, trimmed)
     *  - equipment:    equipment_description (stripped, trimmed)
     *  - facts:        list of ['label' => string, 'value' => string, 'icon' => string]
     *
     * Values are only included when the underlying property field is present,
     * so the views can simply iterate and render whatever is available.
     */
    protected function buildShowcase($property): array
    {
        if (!$property) {
            return [
                'badges' => [],
                'description' => '',
                'location' => '',
                'equipment' => '',
                'facts' => [],
            ];
        }

        $badges = [];
        if (!empty($property->total_units)) {
            $badges[] = ['label' => $property->total_units . ' Wohneinheiten'];
        }
        if (!empty($property->construction_year)) {
            $badges[] = ['label' => 'Fertigstellung ' . $property->construction_year];
        }
        if (!empty($property->purchase_price) && (float) $property->purchase_price > 0) {
            $badges[] = ['label' => 'ab ' . $this->formatPrice((float) $property->purchase_price)];
        } elseif (!empty($property->rental_price) && (float) $property->rental_price > 0) {
            $badges[] = ['label' => 'Miete ab ' . $this->formatPrice((float) $property->rental_price) . '/Monat'];
        }

        $facts = [];
        if (!empty($property->total_units)) {
            $facts[] = ['label' => 'Einheiten', 'value' => (string) $property->total_units, 'icon' => 'units'];
        }
        if (!empty($property->construction_year)) {
            $facts[] = ['label' => 'Fertigstellung', 'value' => (string) $property->construction_year, 'icon' => 'year'];
        }
        if (!empty($property->purchase_price) && (float) $property->purchase_price > 0) {
            $facts[] = ['label' => 'Ab-Preis', 'value' => $this->formatPrice((float) $property->purchase_price), 'icon' => 'price'];
        }
        if (!empty($property->living_area) && (float) $property->living_area > 0) {
            $facts[] = ['label' => 'Wohnfläche', 'value' => number_format((float) $property->living_area, 0, ',', '.') . ' m²', 'icon' => 'area'];
        }
        if (!empty($property->city)) {
            $facts[] = ['label' => 'Lage', 'value' => $property->city, 'icon' => 'location'];
        }
        if (!empty($property->energy_type) || !empty($property->heating)) {
            $facts[] = [
                'label' => 'Heizung',
                'value' => $property->heating ?: $property->energy_type,
                'icon' => 'energy',
            ];
        }

        // Cap facts at 4 to keep the grid tidy
        $facts = array_slice($facts, 0, 4);

        // Koordinaten (verschleierte Kartenansicht) — nur weitergeben, wenn
        // beide Werte gesetzt und != 0 sind. Die Adresse selbst wird nicht
        // angezeigt; die Karte hat einen Umkreis-Kreis statt Marker.
        $lat = $property->latitude ?? null;
        $lng = $property->longitude ?? null;
        $coords = null;
        if ($lat !== null && $lng !== null && (float) $lat != 0 && (float) $lng != 0) {
            $coords = [
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'region' => trim(($property->zip ?? '') . ' ' . ($property->city ?? '')),
            ];
        }

        return [
            'badges' => $badges,
            'description' => $this->cleanText($property->realty_description ?? '', 520),
            'location' => $this->cleanText($property->location_description ?? '', 420),
            'equipment' => $this->cleanText($property->equipment_description ?? '', 360),
            'facts' => $facts,
            'coords' => $coords,
        ];
    }

    /**
     * Format a price as "123.456 €" (German locale, no decimals).
     */
    protected function formatPrice(float $value): string
    {
        return number_format($value, 0, ',', '.') . ' €';
    }

    /**
     * Strip HTML, collapse whitespace, and optionally truncate long
     * property description fields to a soft character budget. When
     * $maxChars is set, the string is cut at the closest sentence /
     * paragraph boundary BEFORE the limit and an ellipsis is appended.
     * Boundaries outside 60% of the budget are ignored so we don't end
     * up with a near-empty paragraph.
     */
    protected function cleanText(string $raw, int $maxChars = 0): string
    {
        $stripped = trim(strip_tags($raw));
        // Normalise excessive blank lines but keep paragraph breaks
        $normalised = preg_replace("/\r\n|\r/", "\n", $stripped);
        $normalised = preg_replace("/\n{3,}/", "\n\n", $normalised);
        $normalised = $normalised ?? '';

        if ($maxChars <= 0 || mb_strlen($normalised) <= $maxChars) {
            return $normalised;
        }

        $cut = mb_substr($normalised, 0, $maxChars);

        // Prefer a paragraph break near the end of the budget.
        $paraBoundary = mb_strrpos($cut, "\n\n");
        if ($paraBoundary !== false && $paraBoundary >= (int) ($maxChars * 0.6)) {
            return rtrim(mb_substr($cut, 0, $paraBoundary)) . ' …';
        }

        // Otherwise cut at the last sentence end (. ! ?) inside the budget.
        $candidates = [];
        foreach (['. ', '! ', '? ', '.' . "\n", '!' . "\n", '?' . "\n"] as $sep) {
            $pos = mb_strrpos($cut, $sep);
            if ($pos !== false) $candidates[] = $pos + mb_strlen($sep) - 1;
        }
        $boundary = empty($candidates) ? false : max($candidates);
        if ($boundary !== false && $boundary >= (int) ($maxChars * 0.6)) {
            return rtrim(mb_substr($cut, 0, $boundary + 1)) . ' …';
        }

        // Last resort: hard cut with ellipsis, don't break a word.
        $spaceBoundary = mb_strrpos($cut, ' ');
        if ($spaceBoundary !== false && $spaceBoundary >= (int) ($maxChars * 0.6)) {
            $cut = mb_substr($cut, 0, $spaceBoundary);
        }
        return rtrim($cut) . ' …';
    }

    public function unlock(Request $request, string $token)
    {
        $link = PropertyLink::where('token', $token)->first();
        abort_unless($link, 404);

        if ($link->revoked_at || ($link->expires_at && $link->expires_at->isPast())) {
            return response()->view('docs.error', ['reason' => $link->revoked_at ? 'revoked' : 'expired', 'link' => $link], 410);
        }

        $rateLimitKey = "unlock:{$token}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return response()->view('docs.error', ['reason' => 'rate_limited', 'link' => $link], 429);
        }
        RateLimiter::hit($rateLimitKey, 3600);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'dsgvo' => ['required', 'accepted'],
        ]);

        $email = strtolower(trim($data['email']));
        $salt = config('app.key');

        $session = PropertyLinkSession::where('property_link_id', $link->id)
            ->where('email', $email)
            ->where('last_seen_at', '>', now()->subDay())
            ->first();

        if (!$session) {
            $session = PropertyLinkSession::create([
                'property_link_id' => $link->id,
                'email' => $email,
                'dsgvo_accepted_at' => now(),
                'ip_hash' => hash('sha256', $request->ip() . $salt),
                'user_agent_hash' => hash('sha256', ($request->userAgent() ?? '') . $salt),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'created_at' => now(),
            ]);
        } else {
            $session->update(['last_seen_at' => now()]);
        }

        // Log first "link_opened" event and activity
        $this->logger->recordEvent($session, \App\Models\PropertyLinkEvent::TYPE_LINK_OPENED);
        $this->logger->recordLinkOpened($session);

        // Set session cookie
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $cookieValue = $session->id . '.' . hash_hmac('sha256', (string) $session->id, $salt);

        return redirect("/docs/{$link->token}")->cookie(
            $cookieName,
            $cookieValue,
            1440, // 24h
            '/',
            null,
            true, // secure
            true, // httpOnly
            false,
            'lax'
        );
    }

    public function file(Request $request, string $token, int $fileId, string $mode)
    {
        $link = PropertyLink::where('token', $token)->first();
        abort_unless($link, 404);
        abort_if($link->revoked_at || ($link->expires_at && $link->expires_at->isPast()), 410);

        $session = $this->resolveSessionFromCookie($request, $link);
        abort_unless($session, 403);

        // Check the file belongs to the link
        $allowed = DB::table('property_link_documents')
            ->where('property_link_id', $link->id)
            ->where('property_file_id', $fileId)
            ->exists();
        abort_unless($allowed, 403);

        $file = DB::table('property_files')
            ->where('id', $fileId)
            ->where('property_id', $link->property_id)
            ->first();
        abort_unless($file, 404);

        // Property files are uploaded to the public disk (storage/app/public/property_files/...)
        // but historical files may live on the local disk. Try public first, fall back to local.
        $disk = null;
        foreach (['public', 'local'] as $diskName) {
            $candidate = \Storage::disk($diskName);
            if ($candidate->exists($file->path)) {
                $disk = $candidate;
                break;
            }
        }
        abort_unless($disk, 404);

        // Log the event
        $eventType = $mode === 'download'
            ? \App\Models\PropertyLinkEvent::TYPE_DOC_DOWNLOADED
            : \App\Models\PropertyLinkEvent::TYPE_DOC_VIEWED;
        $this->logger->recordEvent($session, $eventType, $fileId);

        $headers = [
            'Content-Type' => $file->mime_type ?? 'application/pdf',
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $mode === 'download' ? 'attachment' : 'inline',
                addslashes($file->filename),
            ),
            'Cache-Control' => 'no-store, private',
        ];

        return response($disk->get($file->path), 200, $headers);
    }

    public function event(Request $request, string $token)
    {
        $link = PropertyLink::where('token', $token)->first();
        abort_unless($link, 404);
        abort_if($link->revoked_at || ($link->expires_at && $link->expires_at->isPast()), 410);

        $session = $this->resolveSessionFromCookie($request, $link);
        abort_unless($session, 403);

        $data = $request->validate([
            'type' => ['required', 'in:doc_viewed,doc_downloaded'],
            'file_id' => ['nullable', 'integer'],
            'duration_s' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        // Reject file_id that does not belong to this link's pivot set
        if (!empty($data['file_id'])) {
            $allowed = DB::table('property_link_documents')
                ->where('property_link_id', $link->id)
                ->where('property_file_id', $data['file_id'])
                ->exists();
            if (!$allowed) {
                return response()->json(['error' => 'invalid_file'], 422);
            }
        }

        // Rate limit: 100 events/session/hour
        $key = "event:session:{$session->id}";
        if (RateLimiter::tooManyAttempts($key, 100)) {
            return response()->json(['error' => 'rate_limited'], 429);
        }
        RateLimiter::hit($key, 3600);

        $this->logger->recordEvent(
            $session,
            $data['type'],
            $data['file_id'] ?? null,
            $data['duration_s'] ?? null,
        );

        return response()->json(['ok' => true]);
    }

    public function expose(Request $request, string $token): Response
    {
        $link = PropertyLink::where('token', $token)->first();
        if (!$link) return response()->view('docs.error', ['reason' => 'not_found'], 404);
        if ($link->revoked_at) return response()->view('docs.error', ['reason' => 'revoked', 'link' => $link], 410);
        if ($link->expires_at?->isPast()) return response()->view('docs.error', ['reason' => 'expired', 'link' => $link], 410);

        $pdfBypass = $request->query('pdf_bypass');
        $session = $this->resolveSessionFromCookie($request, $link);

        // Session required, unless the PDF-bypass HMAC is valid (used when Puppeteer
        // renders via exposePdf() which does not have the user's cookie).
        if (!$session && !$pdfBypass) {
            return response('Forbidden', 403);
        }

        // Resolve expose version from pivot.
        $versionId = \DB::table('property_link_documents')
            ->where('property_link_id', $link->id)
            ->whereNotNull('expose_version_id')
            ->value('expose_version_id');

        if (!$versionId) return response('Not found', 404);

        if ($pdfBypass) {
            $expected = hash_hmac('sha256', $versionId . '|' . $token, config('app.key'));
            if (!hash_equals($expected, $pdfBypass)) return response('Forbidden', 403);
        }

        $version = \App\Models\PropertyExposeVersion::find($versionId);
        if (!$version) return response('Not found', 404);

        if ($session) {
            $this->logger->recordEvent($session, 'expose_view');
        }

        $pagination = app(\App\Services\Expose\ExposePaginationService::class);
        $ctx = \App\Services\Expose\ExposeRenderContext::build($version, $pagination);
        return response()->view('expose.layout', ['ctx' => $ctx]);
    }

    public function exposePdf(Request $request, string $token): Response
    {
        $link = PropertyLink::where('token', $token)->first();
        if (!$link) return response('Not found', 404);
        if ($link->revoked_at || $link->expires_at?->isPast()) return response('Gone', 410);

        $session = $this->resolveSessionFromCookie($request, $link);
        if (!$session) return response('Forbidden', 403);

        $versionId = \DB::table('property_link_documents')
            ->where('property_link_id', $link->id)
            ->whereNotNull('expose_version_id')
            ->value('expose_version_id');

        if (!$versionId) return response('Not found', 404);

        $bypass = hash_hmac('sha256', $versionId . '|' . $token, config('app.key'));
        $url = url("/docs/{$token}/expose") . '?pdf_bypass=' . $bypass;

        $pdfService = app(\App\Services\Expose\ExposePdfService::class);
        $binary = $pdfService->renderFromUrl($url);

        $this->logger->recordEvent($session, 'expose_pdf_download');

        $filename = 'Expose-' . \Illuminate\Support\Str::slug($link->property->title ?? 'immobilie') . '.pdf';
        return response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($binary),
        ]);
    }

    protected function resolveSessionFromCookie(Request $request, PropertyLink $link): ?PropertyLinkSession
    {
        $cookieName = 'sr_link_session_' . substr($link->token, 0, 8);
        $cookieValue = $request->cookie($cookieName);

        if (!$cookieValue || !str_contains($cookieValue, '.')) {
            return null;
        }

        [$sessionId, $hmac] = explode('.', $cookieValue, 2);

        if (!hash_equals(hash_hmac('sha256', $sessionId, config('app.key')), $hmac)) {
            return null;
        }

        return PropertyLinkSession::where('id', $sessionId)
            ->where('property_link_id', $link->id)
            ->first();
    }
}
