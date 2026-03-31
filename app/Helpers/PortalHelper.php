<?php

namespace App\Helpers;

/**
 * Portal-related helper methods used across controllers.
 *
 * Centralizes portal detection, source normalization, and
 * common formatting utilities for the SR-Homes application.
 */
class PortalHelper
{
    // ----------------------------------------------------------------
    //  Known portal identifiers (parenthetical suffixes in stakeholder names)
    // ----------------------------------------------------------------
    public const PORTALS = [
        'willhaben'    => 'Willhaben',
        'immoscout24'  => 'ImmoScout24',
        'immoscout'    => 'ImmoScout24',
        'immowelt'     => 'Immowelt',
        'kleinanzeigen' => 'Kleinanzeigen',
        'openimmo'     => 'OpenImmo',
        'website'      => 'Website',
        'homepage'     => 'Website',
    ];

    // ----------------------------------------------------------------
    //  Activity categories
    // ----------------------------------------------------------------
    public const INBOUND_CATEGORIES  = ['anfrage', 'email-in'];
    public const OUTBOUND_CATEGORIES = ['email-out', 'expose', 'antwort'];
    public const VIEWING_CATEGORIES  = ['besichtigung'];

    // ================================================================
    //  extractPortal()  –  detect portal from stakeholder name
    //
    //  "Christine Grünauer (willhaben)" → "Willhaben"
    //  "Hans Müller" → null
    // ================================================================
    public static function extractPortal(string $stakeholderName): ?string
    {
        if (preg_match('/\(([^)]+)\)\s*$/', $stakeholderName, $m)) {
            $key = strtolower(trim($m[1]));
            return self::PORTALS[$key] ?? $m[1];
        }

        return null;
    }

    // ================================================================
    //  stripPortalSuffix()  –  remove " (portal)" from name
    //
    //  "Christine Grünauer (willhaben)" → "Christine Grünauer"
    // ================================================================
    public static function stripPortalSuffix(string $name): string
    {
        return trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $name));
    }

    // ================================================================
    //  isInbound() / isOutbound()  –  category classification
    // ================================================================
    public static function isInbound(string $category): bool
    {
        return in_array(strtolower($category), self::INBOUND_CATEGORIES, true);
    }

    public static function isOutbound(string $category): bool
    {
        return in_array(strtolower($category), self::OUTBOUND_CATEGORIES, true);
    }

    // ================================================================
    //  formatDate()  –  German date formatting
    //
    //  "2026-03-15" → "15.03.2026"
    // ================================================================
    public static function formatDate(?string $date): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($date)->format('d.m.Y');
        } catch (\Exception $e) {
            return $date;
        }
    }

    // ================================================================
    //  formatDateTime()  –  German date+time formatting
    //
    //  "2026-03-15 14:30:00" → "15.03.2026 14:30"
    // ================================================================
    public static function formatDateTime(?string $datetime): string
    {
        if (empty($datetime)) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($datetime)->format('d.m.Y H:i');
        } catch (\Exception $e) {
            return $datetime;
        }
    }

    // ================================================================
    //  daysSince()  –  days between a date and now
    // ================================================================
    public static function daysSince(?string $date): ?int
    {
        if (empty($date)) {
            return null;
        }

        try {
            return (int) \Carbon\Carbon::parse($date)->diffInDays(now());
        } catch (\Exception $e) {
            return null;
        }
    }

    // ================================================================
    //  truncate()  –  safely truncate UTF-8 text
    // ================================================================
    public static function truncate(?string $text, int $length = 100, string $suffix = '...'): string
    {
        if (empty($text)) {
            return '';
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . $suffix;
    }

    // ================================================================
    //  propertyLabel()  –  build display label for a property
    //
    //  Returns "REF-123 | Musterstraße 1, Wien" or similar.
    // ================================================================
    public static function propertyLabel(?string $refId, ?string $address, ?string $city): string
    {
        $parts = [];

        if (!empty($refId)) {
            $parts[] = $refId;
        }

        $location = trim(($address ?? '') . ', ' . ($city ?? ''), ', ');
        if (!empty($location)) {
            $parts[] = $location;
        }

        return implode(' | ', $parts) ?: '(kein Objekt)';
    }

    // ================================================================
    //  categoryBadgeClass()  –  CSS class for activity categories
    // ================================================================
    public static function categoryBadgeClass(string $category): string
    {
        return match (strtolower($category)) {
            'anfrage'       => 'bg-blue-100 text-blue-800',
            'email-in'      => 'bg-green-100 text-green-800',
            'email-out'     => 'bg-purple-100 text-purple-800',
            'expose'        => 'bg-yellow-100 text-yellow-800',
            'eigentuemer'   => 'bg-teal-100 text-teal-800',
            'besichtigung'  => 'bg-orange-100 text-orange-800',
            'antwort'       => 'bg-indigo-100 text-indigo-800',
            'kaufanbot'     => 'bg-red-100 text-red-800',
            'reservierung'  => 'bg-pink-100 text-pink-800',
            default         => 'bg-gray-100 text-gray-800',
        };
    }

    // ================================================================
    //  categoryLabel()  –  German display label for categories
    // ================================================================
    public static function categoryLabel(string $category): string
    {
        return match (strtolower($category)) {
            'anfrage'       => 'Anfrage',
            'email-in'      => 'E-Mail Eingang',
            'email-out'     => 'E-Mail Ausgang',
            'expose'        => 'Exposé',
            'eigentuemer'   => 'Eigentümer',
            'besichtigung'  => 'Besichtigung',
            'antwort'       => 'Antwort',
            'kaufanbot'     => 'Kaufanbot',
            'reservierung'  => 'Reservierung',
            default         => ucfirst($category),
        };
    }
}
