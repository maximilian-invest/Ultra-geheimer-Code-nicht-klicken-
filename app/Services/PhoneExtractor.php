<?php

namespace App\Services;

/**
 * PhoneExtractor — Zentraler Service für Telefonnummer-Extraktion aus E-Mail-Texten.
 *
 * Wird verwendet in: ImapService, DashboardController, FollowupController,
 * KaufanbotController. Unterstützte Formate: AT (+43 / 06x), DE (+49),
 * internationale Nummern.
 *
 * KRITISCH: Filtert eigene SR-Homes-Nummern raus (broker-phone Leakage), damit
 * nicht die eigene Nummer fälschlich als Interessenten-Telefon gespeichert wird.
 */
class PhoneExtractor
{
    /**
     * Eigene Nummern (SR-Homes) als reine Ziffernfolgen — werden nie als
     * Interessenten-Telefon zurückgegeben. Vergleich erfolgt digit-normalisiert,
     * damit "+43 664 2600 930", "0664-2600-930", "06642600930" alle matchen.
     *
     * Wir matchen auch verstuemmelte Varianten (z.B. "+43 664 2600" ohne den
     * Rest), die durch fehlerhafte Regex-Truncation in der Vergangenheit in
     * Kontakte gelandet sind.
     */
    private static array $ownNumberDigits = [
        '436642600930',  // +43 664 2600 930 (vollstaendig international)
        '06642600930',   // 0664 2600 930 (vollstaendig national)
        '6642600930',    // 664 2600 930 (ohne Vorwahl)
        '4366426',       // truncated: "+43 664 26..." (Regex-Abbruch)
        '0664260',       // truncated: "0664 260..." (Regex-Abbruch)
        '664260',        // truncated: "664 260..." (Regex-Abbruch)
        '62459305',      // alte SR-Homes-Festnetznummer (legacy skip-Liste)
    ];

    /**
     * Regex-Patterns in absteigender Spezifität.
     * Labeled patterns (Phone, Telefon, Mobil, Tel) haben Vorrang vor rohen Nummern.
     */
    // Trennzeichen NACH dem Label sind optional (Typeform serialisiert
    // Label und Wert ohne Whitespace: "Phone number+436765118113"). Daher
    // [:\s]* statt [:\s]+ — der nachfolgende [+]?\d sorgt dafuer dass nur
    // bei einer echten Nummer gematcht wird.
    private static array $patterns = [
        '/Phone\s*number[:\s]*([+]?\d[\d\s.()\/-]{7,17})/i',
        '/Telefon(?:nummer)?[:\s]*([+]?\d[\d\s.()\/-]{7,17})/i',
        '/Mobil(?:nummer)?[:\s]*([+]?\d[\d\s.()\/-]{7,17})/i',
        '/Handy(?:nummer)?[:\s]*([+]?\d[\d\s.()\/-]{7,17})/i',
        '/Tel[.:\s]*([+]?\d[\d\s.()\/-]{7,17})/i',
        // Internationale AT/DE-Nummern ohne Label (+43, +49 ...)
        '/(?<![\w@])([+]4[0-9][\d\s.\-\/()]{8,16})(?![\w@])/',
        // Österreichische Mobilnummern ohne Vorwahl-Plus (06x ...)
        '/(?<![\w@])(06\d[\d\s.\-\/()]{6,14})(?![\w@])/',
    ];

    /**
     * Extrahiert die erste gültige, nicht-eigene Telefonnummer aus einem Text.
     *
     * Iteriert ALLE Matches eines Patterns, nicht nur den ersten — falls die
     * Mail im Body zuerst die Makler-Signatur und dann die Interessenten-
     * Nummer enthaelt, bekommen wir trotzdem die richtige.
     *
     * @param  string $text  E-Mail-Body oder beliebiger Text
     * @return string|null   Formatierte Telefonnummer oder null
     */
    public static function extractFromText(string $text): ?string
    {
        foreach (self::$patterns as $pattern) {
            if (preg_match_all($pattern, $text, $ms)) {
                foreach ($ms[1] as $candidate) {
                    $phone = trim($candidate);
                    if (self::isValid($phone) && !self::isOwnNumber($phone)) {
                        return $phone;
                    }
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
        $digits = self::digitsOnly($phone);
        return strlen($digits) >= 7 && strlen($digits) <= 15;
    }

    /**
     * Reduziert eine Telefonnummer auf reine Ziffern. Fuehrendes "+" wird
     * verworfen — bei AT-Nummern ist die Ziffernfolge eindeutig genug fuer
     * Vergleichszwecke.
     */
    public static function digitsOnly(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }

    /**
     * Prüft ob es sich um eine interne SR-Homes-Nummer handelt. Vergleich ist
     * digit-normalisiert — funktioniert unabhaengig von Spaces/Dashes/Slashes.
     *
     * Auch verstuemmelte Varianten (z.B. nur "+43 664 2600") werden gefangen,
     * weil legacy contacts mit Truncation existieren.
     */
    public static function isOwnNumber(string $phone): bool
    {
        $digits = self::digitsOnly($phone);
        if ($digits === '') return false;
        foreach (self::$ownNumberDigits as $own) {
            if (str_contains($digits, $own)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sanitizer fuer alle Schreibpfade in die contacts-Tabelle. Gibt die Nummer
     * unveraendert zurueck — oder NULL, wenn es die Makler-Nummer ist.
     *
     * Aufruf: vor JEDEM contacts.phone INSERT/UPDATE einsetzen, damit auch
     * ueber andere Wege (Frontend, manuelle Eingabe, AI-Tools) nie die eigene
     * Nummer reinrutscht.
     */
    public static function sanitizeForContact(?string $phone): ?string
    {
        if (!$phone) return null;
        $phone = trim($phone);
        if ($phone === '') return null;
        if (!self::isValid($phone)) return null;
        if (self::isOwnNumber($phone)) return null;
        return $phone;
    }
}
