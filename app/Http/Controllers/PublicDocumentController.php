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

        if ($session) {
            $files = DB::table('property_files')
                ->whereIn('id', $link->documentIds())
                ->get();

            return response()->view('docs.landing', [
                'link' => $link,
                'session' => $session,
                'files' => $files,
                'state' => 'unlocked',
            ]);
        }

        return response()->view('docs.landing', [
            'link' => $link,
            'state' => 'locked',
        ]);
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

        $file = DB::table('property_files')->where('id', $fileId)->first();
        abort_unless($file, 404);

        $disk = \Storage::disk('local');
        abort_unless($disk->exists($file->path), 404);

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
