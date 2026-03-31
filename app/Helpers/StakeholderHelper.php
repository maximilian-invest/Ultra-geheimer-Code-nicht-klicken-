<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Stakeholder normalization helpers.
 *
 * Migrated from legacy admin_api.php functions:
 *   normSH(), normSHSurname(), normalizeSurnamePHP(),
 *   systemStakeholderFilter(), partnerExcludeFilter()
 */
class StakeholderHelper
{
    // ----------------------------------------------------------------
    //  UMLAUT MAP (used in both SQL and PHP normalization)
    // ----------------------------------------------------------------
    private const UMLAUT_FROM = ['ü', 'ö', 'ä', 'Ü', 'Ö', 'Ä', 'ß', 'é', 'è'];
    private const UMLAUT_TO   = ['ue', 'oe', 'ae', 'Ue', 'Oe', 'Ae', 'ss', 'e', 'e'];

    // ================================================================
    //  SQL-SIDE: normSH()  –  full-name normalization for queries
    //
    //  Generates a MySQL expression that normalizes a stakeholder column:
    //    1. Strip parenthetical suffix  " (willhaben)"
    //    2. Strip " / SecondName"
    //    3. Strip " - Company"
    //    4. Remove trailing digits
    //    5. Replace umlauts
    //    6. Remove spaces
    //    7. LOWER + TRIM
    // ================================================================
    public static function normSH(string $col = 'a.stakeholder'): string
    {
        // Step 1: Strip " (xxx)" parenthetical suffix
        $s1 = "CASE WHEN {$col} LIKE '% (%)'"
            . " THEN TRIM(LEFT({$col}, CHAR_LENGTH({$col}) - CHAR_LENGTH(SUBSTRING_INDEX({$col}, ' (', -1)) - 2))"
            . " ELSE {$col} END";

        // Step 2: Strip " / xxx" couple suffix
        $s2 = "CASE WHEN ({$s1}) LIKE '% / %'"
            . " THEN TRIM(LEFT(({$s1}), LOCATE(' / ', ({$s1})) - 1))"
            . " ELSE ({$s1}) END";

        // Step 3: Strip " - xxx" company suffix
        $s3 = "CASE WHEN ({$s2}) LIKE '% - %'"
            . " THEN TRIM(LEFT(({$s2}), LOCATE(' - ', ({$s2})) - 1))"
            . " ELSE ({$s2}) END";

        // Steps 4-7: trailing digits, umlauts, remove spaces+dots+dashes, lower+trim
        $umlautReplaced = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE("
            . "REGEXP_REPLACE(({$s3}), '[0-9]+$', '')"
            . ", 'ü', 'ue'), 'ö', 'oe'), 'ä', 'ae')"
            . ", 'Ü', 'Ue'), 'Ö', 'Oe'), 'Ä', 'Ae')"
            . ", 'ß', 'ss'), 'é', 'e'), 'è', 'e')";
        return "LOWER(REPLACE(REPLACE(REPLACE(TRIM({$umlautReplaced}), ' ', ''), '.', ''), '-', ''))";
    }

    // ================================================================
    //  SQL-SIDE: normSHSurname()  –  surname-only normalization
    //
    //  Same stripping as normSH, but then extracts SURNAME only:
    //    "Hans Heit" → "heit"   |   "H.Heit" → "heit"
    // ================================================================
    public static function normSHSurname(string $col = 'a.stakeholder'): string
    {
        // Steps 1-3: identical to normSH
        $s1 = "CASE WHEN {$col} LIKE '% (%)'"
            . " THEN TRIM(LEFT({$col}, CHAR_LENGTH({$col}) - CHAR_LENGTH(SUBSTRING_INDEX({$col}, ' (', -1)) - 2))"
            . " ELSE {$col} END";

        $s2 = "CASE WHEN ({$s1}) LIKE '% / %'"
            . " THEN TRIM(LEFT(({$s1}), LOCATE(' / ', ({$s1})) - 1))"
            . " ELSE ({$s1}) END";

        $s3 = "CASE WHEN ({$s2}) LIKE '% - %'"
            . " THEN TRIM(LEFT(({$s2}), LOCATE(' - ', ({$s2})) - 1))"
            . " ELSE ({$s2}) END";

        // Step 4: surname only – last word (space) or after last dot
        $s4 = "CASE"
            . " WHEN TRIM(({$s3})) LIKE '% %' THEN SUBSTRING_INDEX(TRIM(({$s3})), ' ', -1)"
            . " WHEN TRIM(({$s3})) LIKE '%.%' THEN SUBSTRING_INDEX(TRIM(({$s3})), '.', -1)"
            . " ELSE TRIM(({$s3})) END";

        // Steps 5-7: trailing digits, umlauts, lower+trim (no space removal for surname)
        return "LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE("
            . "REGEXP_REPLACE(({$s4}), '[0-9]+$', '')"
            . ", 'ü', 'ue'), 'ö', 'oe'), 'ä', 'ae')"
            . ", 'Ü', 'Ue'), 'Ö', 'Oe'), 'Ä', 'Ae')"
            . ", 'ß', 'ss'), 'é', 'e'), 'è', 'e')"
            . "))";
    }

    // ================================================================
    //  SQL-SIDE: systemStakeholderFilter()
    //
    //  Returns SQL WHERE clause fragment that excludes system/bot names.
    // ================================================================
    public static function systemStakeholderFilter(string $col = 'a.stakeholder'): string
    {
        return "{$col} NOT IN ('Info','Noreply','noreply','Calendly','System','admin','postmaster')"
            . " AND {$col} NOT LIKE 'noreply%'"
            . " AND {$col} NOT LIKE 'info@%'"
            . " AND {$col} NOT LIKE 'no-reply%'"
            . " AND CHAR_LENGTH({$col}) > 2";
    }

    // ================================================================
    //  SQL-SIDE: partnerExcludeFilter()
    //
    //  Returns SQL WHERE clause that excludes stakeholders who are
    //  known partners/developers/owners in the contacts table.
    // ================================================================
    public static function partnerExcludeFilter(string $col = 'a.stakeholder'): string
    {
        $normS = self::normSHSurname($col);

        return "NOT EXISTS ("
            . " SELECT 1 FROM contacts c"
            . " WHERE c.role IN ('partner','bautraeger','intern','makler','eigentuemer')"
            . " AND " . self::normSHSurname('c.full_name COLLATE utf8mb4_unicode_ci')
            . " = {$normS} COLLATE utf8mb4_unicode_ci"
            . ")";
    }

    // ================================================================
    //  PHP-SIDE: normalizeName()  –  full-name normalization in PHP
    //
    //  Mirrors the SQL normSH() logic for use in application code.
    //  Example: "Christine Grünauer / Matthäus (willhaben)" → "christinegruenauer"
    // ================================================================
    public static function normalizeName(string $name): string
    {
        // Step 1: Strip " (xxx)" parenthetical suffix
        $name = preg_replace('/\s*\(.*?\)\s*$/', '', $name);

        // Step 2: Strip " / xxx" couple suffix
        if (($p = strpos($name, ' / ')) !== false) {
            $name = substr($name, 0, $p);
        }

        // Step 3: Strip " - xxx" company suffix
        if (($p = strpos($name, ' - ')) !== false) {
            $name = substr($name, 0, $p);
        }

        $name = trim($name);

        // Step 4: Trailing digits
        $name = preg_replace('/[0-9]+$/', '', $name);

        // Step 5: Umlauts
        $name = str_replace(self::UMLAUT_FROM, self::UMLAUT_TO, $name);

        // Step 6: Remove spaces, dots, dashes
        $name = str_replace([' ', '.', '-'], '', $name);

        // Step 7: Lower + trim
        return strtolower(trim($name));
    }

    // ================================================================
    //  PHP-SIDE: normalizeSurname()  –  surname-only normalization
    //
    //  Mirrors the SQL normSHSurname() logic for use in application code.
    //  Example: "Hans Heit" → "heit"  |  "H.Heit" → "heit"
    // ================================================================
    public static function normalizeSurname(string $name): string
    {
        // Steps 1-3: strip parenthetical, couple, company
        $name = preg_replace('/\s*\(.*?\)\s*$/', '', $name);
        if (($p = strpos($name, ' / ')) !== false) {
            $name = substr($name, 0, $p);
        }
        if (($p = strpos($name, ' - ')) !== false) {
            $name = substr($name, 0, $p);
        }
        $name = trim($name);

        // Step 4: Extract surname – last word or after last dot
        if (strpos($name, ' ') !== false) {
            $parts = explode(' ', $name);
            $name  = end($parts);
        } elseif (strpos($name, '.') !== false) {
            $parts = explode('.', $name);
            $name  = end($parts);
        }

        // Steps 5-7: umlauts, trailing digits, lower+trim
        $name = str_replace(self::UMLAUT_FROM, self::UMLAUT_TO, $name);
        $name = preg_replace('/[0-9]+$/', '', $name);

        return strtolower(trim($name));
    }

    // ================================================================
    //  PHP-SIDE: isSystemStakeholder()
    //
    //  PHP equivalent of systemStakeholderFilter() for application logic.
    // ================================================================
    public static function isSystemStakeholder(string $name): bool
    {
        $name = trim($name);

        if (in_array($name, ['Info', 'Noreply', 'noreply', 'Calendly', 'System', 'admin', 'postmaster'], true)) {
            return true;
        }
        if (str_starts_with(strtolower($name), 'noreply'))  return true;
        if (str_starts_with(strtolower($name), 'info@'))    return true;
        if (str_starts_with(strtolower($name), 'no-reply')) return true;
        if (mb_strlen($name) <= 2) return true;

        return false;
    }
}
