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
    //  PHP-SIDE: formalSalutation()
    //
    //  Liefert eine korrekte deutsche Hoeflichkeits-Anrede basierend auf
    //  einem Stakeholder-Namen — wird in Nachfass-Templates und
    //  KI-generierten Mails verwendet.
    //
    //  Logik:
    //    - "Herr Mustermann" / "Frau Musterfrau" → "Sehr geehrter Herr ..."
    //                                             / "Sehr geehrte Frau ..."
    //    - "Robert Etzelsberger" → Vorname-Gender-Lookup → "Sehr geehrter
    //      Herr Etzelsberger" oder "Sehr geehrte Frau Etzelsberger"
    //    - Unbekannter Vorname → "Guten Tag {Vollname}" (defensiver Fallback,
    //      damit nie ein falsches Geschlecht angesprochen wird)
    //    - Leer → "Sehr geehrte Damen und Herren"
    // ================================================================
    public static function formalSalutation(?string $rawStakeholder): string
    {
        $name = trim((string) ($rawStakeholder ?? ''));

        // Klammer-Suffixe und Trenn-Muster entfernen ("Robert Etzelsberger (willhaben)"
        // → "Robert Etzelsberger"; "Anna / Markus Mustermann" → "Anna").
        $name = preg_replace('/\s*\(.*?\)\s*$/', '', $name);
        if (($p = strpos($name, ' / ')) !== false) $name = substr($name, 0, $p);
        if (($p = strpos($name, ' - ')) !== false) $name = substr($name, 0, $p);
        $name = trim($name);

        if ($name === '') return 'Sehr geehrte Damen und Herren';

        // Wenn der "Name" eine E-Mail-Adresse ist (z.B. weil der Stakeholder
        // beim Mail-Import nie als echter Name extrahiert wurde), bauen wir
        // KEINE personalisierte Anrede. "Sehr geehrter Herr nb2776@gmail.com"
        // ist peinlicher als ein generisches "Sehr geehrte Damen und Herren".
        if (str_contains($name, '@') || filter_var($name, FILTER_VALIDATE_EMAIL)) {
            return 'Sehr geehrte Damen und Herren';
        }

        // Bereits "Herr X" oder "Frau X" → direkt formatieren
        if (preg_match('/^Herr\s+(.+)$/i', $name, $m)) {
            return 'Sehr geehrter Herr ' . trim($m[1]);
        }
        if (preg_match('/^Frau\s+(.+)$/i', $name, $m)) {
            return 'Sehr geehrte Frau ' . trim($m[1]);
        }

        // Vor- und Nachname trennen — letzter Teil = Nachname.
        $parts = preg_split('/\s+/', $name);
        if (count($parts) < 2) {
            // Nur ein Wort → kein klarer Nachname → generischer Geschaefts-Fallback.
            // Vorher: "Guten Tag {Wort}" — wirkt persoenlich-falsch wenn das
            // Wort gar kein Name ist.
            return 'Sehr geehrte Damen und Herren';
        }
        $firstName = $parts[0];
        $lastName  = end($parts);

        $gender = self::guessGenderFromFirstName($firstName);
        if ($gender === 'm') return 'Sehr geehrter Herr ' . $lastName;
        if ($gender === 'f') return 'Sehr geehrte Frau ' . $lastName;

        // Unbekanntes Geschlecht: generischer Geschaefts-Fallback statt
        // "Guten Tag {Vollname}" — letzteres wirkt persoenlich aber wirkt
        // mit Vor- + Nachnamen vertrieblich-aufdringlich. Wenn wir das
        // Geschlecht nicht kennen, ist die formellste Variante besser.
        return 'Sehr geehrte Damen und Herren';
    }

    /**
     * Gender-Detection fuer deutsche/oesterreichische Vornamen.
     * Liste der haeufigsten Vornamen aus Ostarrichi-Statistik (Top ~200 m + ~200 f
     * decken >90% der Faelle ab). Lower-cased, umlaut-normalisiert beim Matchen.
     */
    private static function guessGenderFromFirstName(string $firstName): ?string
    {
        $key = strtolower(str_replace(self::UMLAUT_FROM, self::UMLAUT_TO, trim($firstName)));
        if ($key === '') return null;

        // Doppelnamen ("Hans-Peter") → ersten Teil verwenden.
        if (str_contains($key, '-')) $key = explode('-', $key)[0];

        if (in_array($key, self::MALE_FIRST_NAMES, true))   return 'm';
        if (in_array($key, self::FEMALE_FIRST_NAMES, true)) return 'f';
        return null;
    }

    // Top-Listen der haeufigsten oesterreichischen/deutschen Vornamen.
    // Reine Lookup-Performance: const-array, kein Regex.
    private const MALE_FIRST_NAMES = [
        'alexander','andreas','andre','anton','armin','arnold','arthur','bastian','benjamin','bernd','bernhard',
        'bjoern','boris','christian','christof','christoph','clemens','daniel','david','denis','dennis','didi','dieter',
        'dietmar','dirk','dominik','douglas','edgar','edmund','eduard','elias','elmar','emil','enzo','erhard',
        'erich','erik','ernst','eugen','ewald','fabian','felix','ferdinand','florian','frank','franz','friedrich',
        'fritz','gabriel','georg','gerald','gerhard','gerd','gernot','gunter','guenter','guenther','hannes',
        'hans','harald','hartmut','helmut','helmuth','hendrik','henning','henry','herbert','herwig','holger',
        'horst','hubert','huber','ingo','jakob','jan','jens','joachim','jochen','jonas','josef','julian',
        'juergen','justus','kai','kar','karl','karsten','kevin','klaus','konstantin','konrad','korbinian',
        'kurt','lars','leo','leon','leonhard','linus','lothar','lucas','ludwig','lukas','manuel','marc','marcel',
        'marco','marcus','mario','markus','martin','matthias','matthaeus','max','maximilian','michael','milan',
        'moritz','nico','nicolas','niklas','norbert','olaf','oliver','oskar','otto','patrick','paul','peter',
        'philipp','rainer','ralf','ralph','raphael','rene','reinhard','reinhold','richard','robert','rolf','roland',
        'rudi','rudolf','ruediger','rupert','samuel','sascha','sebastian','siegfried','simon','stefan','steffen',
        'sven','thomas','thorsten','tilmann','tim','timo','tobias','tom','udo','ulf','ulrich','uli','uwe',
        'valentin','viktor','vincent','vinzent','vinzenz','volker','walter','werner','wilfried','wilhelm','willi',
        'wolfgang','wolfram','xaver','yannick',
    ];

    private const FEMALE_FIRST_NAMES = [
        'alexandra','alice','alina','amelie','andrea','angela','angelika','anita','anja','anke','anna','annaliese',
        'annette','annemarie','antonia','astrid','barbara','beate','bettina','birgit','birte','brigitte',
        'caren','caro','caroline','carolin','carla','christa','christel','christiane','christine','christina','claudia',
        'cornelia','daniela','denise','diana','dorothea','dorothee','dorothy','edeltraud','elena','eli','elisabeth',
        'elke','ella','elli','ellinor','emely','emilia','emily','emma','erika','ernestine','esther','eva','evi','evelin',
        'evelyn','fatima','franziska','frieda','friederike','gabi','gabriela','gabriele','gabriella','gerda','gerlinde',
        'gertrud','gertraud','gisela','gloria','grace','greta','gudrun','hanna','hannah','hanne','hannelore',
        'hedwig','heidemarie','heidi','heike','helena','helga','helene','henriette','herta','hilde','hildegard',
        'ida','ilona','ilse','inge','ingeborg','ingrid','irene','iris','irmgard','irmtraud','isabel','isabella',
        'jaqueline','janina','jasmin','jasmine','jenny','jennifer','jessica','jessika','jeannine','jola','johanna',
        'judith','julia','juliane','julianna','justine','jutta','kaethe','karin','karoline','karolin','kassandra',
        'katarina','katharina','kathi','kathleen','kathrin','katja','katrin','klara','konstanze','kristin','kristina',
        'larissa','laura','lea','leah','leni','leonie','liesel','lilly','lina','linda','line','lisa','lisbeth','lotte',
        'lucia','luise','luisa','luna','luzia','lydia','magda','magdalena','magrit','manuela','margarete','margaret',
        'margot','margret','maria','marina','marion','marlene','martha','martina','mascha','melanie','melissa',
        'meike','michaela','milena','mira','miriam','mona','monika','nadine','nadia','nancy','natascha','natalie',
        'nele','nicole','nina','nora','olga','pamela','patricia','patrizia','paula','petra','philippa','pia',
        'rabea','rachel','regina','renate','rosa','rose','rosemarie','roswitha','ruth','sabine','sabrina','sandra',
        'sara','sarah','saskia','silke','silvana','silvia','simone','sina','sonja','sophia','sophie','stefanie',
        'stephanie','steffi','steffanie','susanne','susi','svea','sybille','sylvia','tamara','tanja','tatjana',
        'theresa','therese','tina','traute','ulla','ulrike','ursula','uschi','ute','uta','vanessa','vera','verena',
        'veronika','vera','victoria','viktoria','violetta','vivien','waltraud','wiebke','wilhelmina','yasmin','yvonne',
        'zara','zita','zoey','zoe',
    ];

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
