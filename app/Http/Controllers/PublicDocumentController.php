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
