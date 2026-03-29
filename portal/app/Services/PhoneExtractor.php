<?php

namespace App\Services;

/**
 * PhoneExtractor — Zentraler Service für Telefonnummer-Extraktion aus E-Mail-Texten.
 *
 * Wird verwendet in: ImapService, DashboardController (clusterStakeholders).
 * Unterstützte Formate: AT (+43 / 06x), DE (+49), internationale Nummern.
 */
class PhoneExtractor
{
    /**
     * Eigene Nummern (SR-Homes) — werden nie als Interessenten-Telefon zurückgegeben.
     */
    private static array $ownNumbers = ['6642600930', '664 2600 93'];

    /**
     * Regex-Patterns in absteigender Spezifität.
     * Labeled patterns (Phone, Telefon, Mobil, Tel) haben Vorrang vor rohen Nummern.
     */
    private static array $patterns = [
        '/Phone\s*number[:\s]+([+]?\d[\d\s.()\/-]{7,17})/i',
        '/Telefon(?:nummer)?[:\s]+([+]?\d[\d\s.()\/-]{7,17})/i',
        '/Mobil(?:nummer)?[:\s]+([+]?\d[\d\s.()\/-]{7,17})/i',
        '/Tel[.:\s]+([+]?\d[\d\s.()\/-]{7,17})/i',
        // Internationale AT/DE-Nummern ohne Label (+43, +49 ...)
        '/(?<![\w@])([+]4[0-9][\d\s.-]{8,14})(?![\w@])/',
        // Österreichische Mobilnummern ohne Vorwahl-Plus (06x ...)
        '/(?<![\w@])(06\d[\d\s.-]{6,12})(?![\w@])/',
    ];

    /**
     * Extrahiert die erste gültige, nicht-eigene Telefonnummer aus einem Text.
     *
     * @param  string $text  E-Mail-Body oder beliebiger Text
     * @return string|null   Formatierte Telefonnummer oder null
     */
    public static function extractFromText(string $text): ?string
    {
        foreach (self::$patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $phone = trim($m[1]);
                if (self::isValid($phone) && !self::isOwnNumber($phone)) {
                    return $phone;
                }
            }
        }
        return null;
    }

    /**
     * Prüft ob die Nummer eine plausible Länge hat (7–15 Ziffern).
     */
    public static function isValid(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);
        return strlen($digits) >= 7 && strlen($digits) <= 15;
    }

    /**
     * Prüft ob es sich um eine interne SR-Homes-Nummer handelt.
     */
    public static function isOwnNumber(string $phone): bool
    {
        foreach (self::$ownNumbers as $own) {
            if (strpos($phone, $own) !== false) {
                return true;
            }
        }
        return false;
    }
}
