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
     * Returns [] when no images exist.
     */
    protected function resolvePropertyImages(int $propertyId): array
    {
        $images = DB::table('property_files')
            ->where('property_id', $propertyId)
            ->where('mime_type', 'like', 'image/%')
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

        return [
            'badges' => $badges,
            'description' => $this->cleanText($property->realty_description ?? ''),
            'location' => $this->cleanText($property->location_description ?? ''),
            'equipment' => $this->cleanText($property->equipment_description ?? ''),
            'facts' => $facts,
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
     * Strip HTML, collapse whitespace, and trim long property description fields.
     */
    protected function cleanText(string $raw): string
    {
        $stripped = trim(strip_tags($raw));
        // Normalise excessive blank lines but keep paragraph breaks
        $normalised = preg_replace("/\r\n|\r/", "\n", $stripped);
        $normalised = preg_replace("/\n{3,}/", "\n\n", $normalised);
        return $normalised ?? '';
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
