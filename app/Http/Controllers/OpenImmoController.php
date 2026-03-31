<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OpenImmo XML Feed Generator
 * 
 * Generiert einen OpenImmo 1.2.7 XML-Feed fuer willhaben.at
 * URL: /api/openimmo/willhaben.xml
 */
class OpenImmoController extends Controller
{
    private string $baseUrl = 'https://kundenportal.sr-homes.at';
    private string $anbieterId = 'SR-HOMES-001';
    private string $firma = 'SR-Homes Immobilien GmbH';

    /**
     * Hauptendpunkt: Generiert den kompletten OpenImmo-XML-Feed
     */
    public function willhabenFeed(): \Illuminate\Http\Response
    {
        // Alle Properties laden die fuer willhaben aktiv sind
        $properties = $this->getActiveProperties();
        
        $xml = $this->generateXml($properties);
        
        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * JSON-Status-Endpunkt fuer Admin-Dashboard
     */
    public function feedStatus(): \Illuminate\Http\JsonResponse
    {
        $total = DB::table('property_portals')
            ->where('portal_name', 'willhaben')
            ->where('sync_enabled', true)
            ->count();

        $properties = $this->getActiveProperties();
        
        return response()->json([
            'success' => true,
            'enabled_count' => $total,
            'exported_count' => count($properties),
            'feed_url' => $this->baseUrl . '/api/openimmo/willhaben.xml',
            'last_generated' => now()->toIso8601String(),
            'properties' => collect($properties)->map(fn($p) => [
                'id' => $p->id,
                'address' => $p->address,
                'city' => $p->city,
                'ref_id' => $p->ref_id,
                'image_count' => DB::table('property_images')->where('property_id', $p->id)->where('is_public', 1)->count(),
            ]),
        ]);
    }

    /**
     * Properties laden die im willhaben-Feed sein sollen
     */
    private function getActiveProperties(): array
    {
        // Nur property_portals Tabelle (Toggle im Admin) - kein Fallback auf platforms-Feld
        return DB::table('properties as p')
            ->join('property_portals as pp', function ($j) {
                $j->on('pp.property_id', '=', 'p.id')
                  ->where('pp.portal_name', '=', 'willhaben')
                  ->where('pp.sync_enabled', '=', 1)
                  ->where('pp.realty_status', '!=', 'deleted');
            })
            ->where('p.realty_status', '!=', 'inaktiv')
            ->select('p.*')
            ->get()
            ->all();
    }

    /**
     * Kompletten OpenImmo-XML-String generieren
     */
    private function generateXml(array $properties): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('openimmo');
        $dom->appendChild($root);

        // Uebertragung
        $ue = $dom->createElement('uebertragung');
        $ue->setAttribute('art', 'ONLINE');
        $ue->setAttribute('umfang', 'VOLL');
        $ue->setAttribute('version', '1.2.7');
        $ue->setAttribute('sendersoftware', 'SR-Homes Kundenportal');
        $ue->setAttribute('senderversion', '2.0');
        $ue->setAttribute('timestamp', now()->format('Y-m-d\TH:i:s'));
        $root->appendChild($ue);

        // Anbieter
        $anbieter = $dom->createElement('anbieter');
        $root->appendChild($anbieter);

        $this->addElement($dom, $anbieter, 'openimmo_anid', $this->anbieterId);
        $this->addElement($dom, $anbieter, 'firma', $this->firma);

        // Anbieter-Impressum
        $impressum = $dom->createElement('impressum');
        $this->addElement($dom, $impressum, 'firmenname', $this->firma);
        $this->addElement($dom, $impressum, 'strasse', 'Linzer Bundesstrasse 33');
        $this->addElement($dom, $impressum, 'plz', '5020');
        $this->addElement($dom, $impressum, 'ort', 'Salzburg');
        $this->addElement($dom, $impressum, 'land', 'AT');
        $this->addElement($dom, $impressum, 'telefon', '+43 664 2600 930');
        $this->addElement($dom, $impressum, 'email', 'office@sr-homes.at');
        $this->addElement($dom, $impressum, 'homepage', 'https://www.sr-homes.at');
        $anbieter->appendChild($impressum);

        // Immobilien
        foreach ($properties as $prop) {
            $immo = $this->buildImmobilie($dom, $prop);
            $anbieter->appendChild($immo);
        }

        return $dom->saveXML();
    }

    /**
     * Ein <immobilie>-Element aufbauen
     */
    private function buildImmobilie(\DOMDocument $dom, object $prop): \DOMElement
    {
        $immo = $dom->createElement('immobilie');

        // 1. Objektkategorie
        $this->addObjektkategorie($dom, $immo, $prop);

        // 2. Geo
        $this->addGeo($dom, $immo, $prop);

        // 3. Kontaktperson
        $this->addKontaktperson($dom, $immo, $prop);

        // 4. Preise
        $this->addPreise($dom, $immo, $prop);

        // 5. Flaechen
        $this->addFlaechen($dom, $immo, $prop);

        // 6. Zustand
        $this->addZustand($dom, $immo, $prop);

        // 7. Ausstattung
        $this->addAusstattung($dom, $immo, $prop);

        // 8. Infrastruktur / Energieausweis
        $this->addEnergieausweis($dom, $immo, $prop);

        // 9. Freitexte
        $this->addFreitexte($dom, $immo, $prop);

        // 10. Anhaenge (Bilder)
        $this->addAnhaenge($dom, $immo, $prop);

        // 11. Verwaltung (technisch)
        $this->addVerwaltung($dom, $immo, $prop);

        return $immo;
    }

    // ── Objektkategorie ──────────────────────────────

    private function addObjektkategorie(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $kat = $dom->createElement('objektkategorie');
        $immo->appendChild($kat);

        // Nutzungsart
        $nutzung = $dom->createElement('nutzungsart');
        $nutzung->setAttribute('WOHNEN', 'true');
        $kat->appendChild($nutzung);

        // Vermarktungsart
        $verm = $dom->createElement('vermarktungsart');
        $txType = strtolower($prop->marketing_type ?? 'kauf');
        if ($txType === 'miete') {
            $verm->setAttribute('MIETE', 'true');
        } else {
            $verm->setAttribute('KAUF', 'true');
        }
        $kat->appendChild($verm);

        // Objektart
        $objektart = $dom->createElement('objektart');
        $kat->appendChild($objektart);

        $typeMap = $this->mapPropertyType($prop);
        $typeEl = $dom->createElement($typeMap['tag']);
        if ($typeMap['attr']) {
            $typeEl->setAttribute($typeMap['attrName'], $typeMap['attr']);
        }
        $objektart->appendChild($typeEl);
    }

    private function mapPropertyType(object $prop): array
    {
        $type = strtolower($prop->object_type ?? '');
        $cat = strtolower($prop->property_category ?? '');
        $sub = strtolower($prop->object_subtype ?? '');

        // Neubauprojekt
        if ($cat === 'newbuild' || str_contains($type, 'neubauprojekt') || str_contains($type, 'neubau')) {
            return ['tag' => 'wohnung', 'attrName' => 'wohnungtyp', 'attr' => 'ETAGE'];
        }

        // Haus-Typen
        if ($cat === 'house' || str_contains($type, 'haus') || str_contains($type, 'einfamilienhaus')) {
            $haustyp = 'EINFAMILIENHAUS';
            if (str_contains($type, 'reihenhaus')) $haustyp = 'REIHENHAUS';
            if (str_contains($type, 'doppelhaus')) $haustyp = 'DOPPELHAUSHAELFTE';
            return ['tag' => 'haus', 'attrName' => 'haustyp', 'attr' => $haustyp];
        }

        // Grundstueck
        if ($cat === 'land' || str_contains($type, 'grundst') || str_contains($type, 'baugrund')) {
            return ['tag' => 'grundstueck', 'attrName' => 'grundst_typ', 'attr' => 'WOHNEN'];
        }

        // Gewerbe
        if (str_contains($type, 'gewerbe') || str_contains($type, 'buero') || str_contains($type, 'büro')) {
            return ['tag' => 'buero_praxen', 'attrName' => 'buero_typ', 'attr' => 'BUEROFLAECHE'];
        }

        // Default: Wohnung
        $wtyp = 'ETAGE';
        if (str_contains($type, 'gartenwohnung')) $wtyp = 'ERDGESCHOSS';
        if (str_contains($type, 'penthouse') || str_contains($type, 'dachgeschoss')) $wtyp = 'PENTHOUSE';
        if (str_contains($type, 'maisonette')) $wtyp = 'MAISONETTE';

        return ['tag' => 'wohnung', 'attrName' => 'wohnungtyp', 'attr' => $wtyp];
    }

    // ── Geo ──────────────────────────────────────────

    private function addGeo(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $geo = $dom->createElement('geo');
        $immo->appendChild($geo);

        if ($prop->zip) $this->addElement($dom, $geo, 'plz', $prop->zip);
        if ($prop->city) $this->addElement($dom, $geo, 'ort', $prop->city);
        if ($prop->address) $this->addElement($dom, $geo, 'strasse', $prop->address);

        $land = $dom->createElement('land');
        $land->setAttribute('iso_land', 'AUT');
        $geo->appendChild($land);

        if ($prop->latitude && $prop->longitude) {
            $coords = $dom->createElement('geokoordinaten');
            $coords->setAttribute('breitengrad', (string)$prop->latitude);
            $coords->setAttribute('laengengrad', (string)$prop->longitude);
            $geo->appendChild($coords);
        }
    }

    // ── Kontaktperson ────────────────────────────────

    private function addKontaktperson(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $kp = $dom->createElement('kontaktperson');
        $immo->appendChild($kp);

        // Immer SR-Homes als Kontakt
        $this->addElement($dom, $kp, 'name', 'Maximilian Hoelzl');
        $this->addElement($dom, $kp, 'vorname', 'Maximilian');
        $this->addElement($dom, $kp, 'nachname', 'Hoelzl');
        $this->addElement($dom, $kp, 'firma', $this->firma);
        $this->addElement($dom, $kp, 'telefon_direkt', '+43 664 2600 930');
        $this->addElement($dom, $kp, 'email_direkt', 'office@sr-homes.at');
        $this->addElement($dom, $kp, 'url', 'https://www.sr-homes.at');
    }

    // ── Preise ───────────────────────────────────────

    private function addPreise(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $preise = $dom->createElement('preise');
        $immo->appendChild($preise);

        $txType = strtolower($prop->marketing_type ?? 'kauf');

        if ($txType === 'miete') {
            if ($prop->rental_price) $this->addElement($dom, $preise, 'kaltmiete', number_format($prop->rental_price, 2, '.', ''));
            if ($prop->rent_warm) $this->addElement($dom, $preise, 'warmmiete', number_format($prop->rent_warm, 2, '.', ''));
            if ($prop->rent_deposit) $this->addElement($dom, $preise, 'kaution', number_format($prop->rent_deposit, 2, '.', ''));
        } else {
            if ($prop->purchase_price) {
                $kp = $this->addElement($dom, $preise, 'kaufpreis', number_format($prop->purchase_price, 2, '.', ''));
                $kp->setAttribute('auf_anfrage', $prop->purchase_price ? 'false' : 'true');
            }
        }

        if ($prop->operating_costs) {
            $this->addElement($dom, $preise, 'nebenkosten', number_format($prop->operating_costs, 2, '.', ''));
        }

        // Provision
        if ($prop->buyer_commission_percent || $prop->buyer_commission_text) {
            $provText = $prop->buyer_commission_text ?: ($prop->buyer_commission_percent . '% zzgl. 20% USt.');
            $prov = $this->addElement($dom, $preise, 'provision_aussen', $provText);
        } elseif ($prop->commission_percent) {
            $provText = $prop->commission_percent . '% zzgl. 20% USt.';
            $this->addElement($dom, $preise, 'provision_aussen', $provText);
        }

        $this->addElement($dom, $preise, 'waehrung', 'EUR');
    }

    // ── Flaechen ─────────────────────────────────────

    private function addFlaechen(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $fl = $dom->createElement('flaechen');
        $immo->appendChild($fl);

        if ($prop->living_area) $this->addElement($dom, $fl, 'wohnflaeche', number_format($prop->living_area, 2, '.', ''));
        elseif ($prop->total_area) $this->addElement($dom, $fl, 'wohnflaeche', number_format($prop->total_area, 2, '.', ''));

        if ($prop->free_area) $this->addElement($dom, $fl, 'grundstuecksflaeche', number_format($prop->free_area, 2, '.', ''));
        if ($prop->total_area) $this->addElement($dom, $fl, 'gesamtflaeche', number_format($prop->total_area, 2, '.', ''));
        if ($prop->rooms_amount) $this->addElement($dom, $fl, 'anzahl_zimmer', number_format($prop->rooms_amount, 1, '.', ''));

        // Zusatzflaechen
        $balkon = floatval($prop->area_balcony ?? 0) + floatval($prop->area_terrace ?? 0) + floatval($prop->area_loggia ?? 0);
        if ($balkon > 0) $this->addElement($dom, $fl, 'anzahl_balkone', '1');

        if ($prop->floor_number !== null) $this->addElement($dom, $fl, 'etage', (string)$prop->floor_number);
        if ($prop->floor_count) $this->addElement($dom, $fl, 'anzahl_etagen', (string)$prop->floor_count);
        if ($prop->garage_spaces) $this->addElement($dom, $fl, 'anzahl_stellplaetze', (string)$prop->garage_spaces);
        if ($prop->parking_spaces) $this->addElement($dom, $fl, 'anzahl_stellplaetze', (string)$prop->parking_spaces);
    }

    // ── Zustand ──────────────────────────────────────

    private function addZustand(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $za = $dom->createElement('zustand_angaben');
        $immo->appendChild($za);

        if ($prop->construction_year) {
            $this->addElement($dom, $za, 'baujahr', (string)$prop->construction_year);
        }

        if ($prop->realty_condition) {
            $zustand = $dom->createElement('zustand');
            $map = [
                'erstbezug' => 'ERSTBEZUG',
                'neuwertig' => 'NEUWERTIG',
                'gepflegt' => 'GEPFLEGT',
                'renovierungsbeduerftig' => 'RENOVIERUNGSBEDUERFTIG',
                'saniert' => 'MODERNISIERT',
                'teilsaniert' => 'TEIL_VOLLRENOVIERT',
            ];
            $zustand->setAttribute('zustand_art', $map[$prop->realty_condition] ?? 'GEPFLEGT');
            $za->appendChild($zustand);
        }
    }

    // ── Ausstattung ──────────────────────────────────

    private function addAusstattung(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $aus = $dom->createElement('ausstattung');
        $immo->appendChild($aus);

        if ($prop->has_balcony || $prop->has_terrace || $prop->has_loggia) {
            $this->addBoolElement($dom, $aus, 'balkon', true);
        }
        if ($prop->has_garden) $this->addBoolElement($dom, $aus, 'gartennutzung', true);
        if ($prop->has_elevator) $this->addBoolElement($dom, $aus, 'fahrstuhl', true, 'PERSONEN');
        if ($prop->has_basement || $prop->has_cellar) $this->addBoolElement($dom, $aus, 'unterkellert', true, 'JA');
        if ($prop->has_fitted_kitchen) $this->addBoolElement($dom, $aus, 'kueche', true, 'EBK');
        if ($prop->has_air_conditioning) $this->addBoolElement($dom, $aus, 'klimatisiert', true);
        if ($prop->has_barrier_free) $this->addBoolElement($dom, $aus, 'barrierefrei', true);
        if ($prop->has_pool) $this->addBoolElement($dom, $aus, 'swimmingpool', true);
        if ($prop->has_sauna) $this->addBoolElement($dom, $aus, 'sauna', true);

        if ($prop->garage_spaces || $prop->parking_spaces) {
            $stellplatz = $dom->createElement('stellplatzart');
            $ptype = strtolower($prop->parking_type ?? '');
            if (str_contains($ptype, 'garage') || str_contains($ptype, 'tiefgarage')) {
                $stellplatz->setAttribute('TIEFGARAGE', 'true');
            } elseif (str_contains($ptype, 'carport')) {
                $stellplatz->setAttribute('CARPORT', 'true');
            } else {
                $stellplatz->setAttribute('FREIPLATZ', 'true');
            }
            $aus->appendChild($stellplatz);
        }

        if ($prop->heating) {
            $heizung = $dom->createElement('heizungsart');
            $h = strtolower($prop->heating);
            if (str_contains($h, 'fern')) $heizung->setAttribute('FERN', 'true');
            elseif (str_contains($h, 'fussb') || str_contains($h, 'fußb')) $heizung->setAttribute('FUSSBODEN', 'true');
            elseif (str_contains($h, 'zentral')) $heizung->setAttribute('ZENTRAL', 'true');
            else $heizung->setAttribute('ZENTRAL', 'true');
            $aus->appendChild($heizung);
        }
    }

    // ── Energieausweis ───────────────────────────────

    private function addEnergieausweis(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        if (!$prop->heating_demand_value && !$prop->heating_demand_class) return;

        $infra = $dom->createElement('infrastruktur');
        $immo->appendChild($infra);

        $ea = $dom->createElement('energiepass');
        $infra->appendChild($ea);

        if ($prop->energy_type) {
            $this->addElement($dom, $ea, 'epart', $prop->energy_type === 'Verbrauch' ? 'VERBRAUCH' : 'BEDARF');
        }
        if ($prop->heating_demand_value) {
            $this->addElement($dom, $ea, 'energieverbrauchkennwert', number_format($prop->heating_demand_value, 2, '.', ''));
        }
        if ($prop->heating_demand_class) {
            $this->addElement($dom, $ea, 'wertklasse', strtoupper($prop->heating_demand_class));
        }
        if ($prop->energy_efficiency_value) {
            $this->addElement($dom, $ea, 'fgeewert', number_format($prop->energy_efficiency_value, 2, '.', ''));
        }
        if ($prop->energy_valid_until) {
            $this->addElement($dom, $ea, 'gueltig_bis', $prop->energy_valid_until);
        }
        $this->addElement($dom, $ea, 'mitwarmwasser', 'true');
    }

    // ── Freitexte ────────────────────────────────────

    private function addFreitexte(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $ft = $dom->createElement('freitexte');
        $immo->appendChild($ft);

        $titel = $prop->title ?: $prop->project_name ?: ($prop->object_type . ' in ' . $prop->city);
        $this->addCdataElement($dom, $ft, 'objekttitel', $titel);

        if ($prop->realty_description) {
            $this->addCdataElement($dom, $ft, 'objektbeschreibung', $prop->realty_description);
        }
        if (isset($prop->location_description) && $prop->location_description) {
            $this->addCdataElement($dom, $ft, 'lage', $prop->location_description);
        }
        if (isset($prop->equipment_description) && $prop->equipment_description) {
            $this->addCdataElement($dom, $ft, 'ausstatt_beschr', $prop->equipment_description);
        }
        if (isset($prop->other_description) && $prop->other_description) {
            $this->addCdataElement($dom, $ft, 'spiegel_text', $prop->other_description);
        }
    }

    // ── Anhaenge (Bilder) ────────────────────────────

    private function addAnhaenge(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $images = DB::table('property_images')
            ->where('property_id', $prop->id)
            ->where('is_public', 1)
            ->orderByDesc('is_title_image')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($images->isEmpty()) return;

        $anhaenge = $dom->createElement('anhaenge');
        $immo->appendChild($anhaenge);

        foreach ($images as $i => $img) {
            $anhang = $dom->createElement('anhang');
            $anhang->setAttribute('location', 'EXTERN');
            $anhaenge->appendChild($anhang);

            $gruppe = $img->is_title_image ? 'TITELBILD' : ($img->is_floorplan ? 'GRUNDRISS' : 'BILD');
            $this->addElement($dom, $anhang, 'anhangtitel', $img->title ?: ($img->category ?: 'Bild ' . ($i + 1)));
            $this->addElement($dom, $anhang, 'format', $img->mime_type ?: 'image/jpeg');

            $daten = $dom->createElement('daten');
            $anhang->appendChild($daten);
            $this->addElement($dom, $daten, 'pfad', $this->baseUrl . '/storage/' . $img->path);

            // willhaben-Gruppe
            $this->addElement($dom, $anhang, 'gruppe', $gruppe);
        }
    }

    // ── Verwaltung Technisch ─────────────────────────

    private function addVerwaltung(\DOMDocument $dom, \DOMElement $immo, object $prop): void
    {
        $vt = $dom->createElement('verwaltung_techn');
        $immo->appendChild($vt);

        // Externe Objektnummer (Ref-ID)
        $objnr = $prop->ref_id ?: ('SRHOMES-' . $prop->id);
        $this->addElement($dom, $vt, 'objektnr_extern', $objnr);

        // OpenImmo Object-ID
        $obid = $prop->openimmo_id ?: ('srhomes-property-' . $prop->id);
        $this->addElement($dom, $vt, 'openimmo_obid', $obid);

        // Anbieter-ID
        $this->addElement($dom, $vt, 'kennung_ursprung', $this->anbieterId);

        // Aktion: CHANGE = Update/Insert
        $aktion = $dom->createElement('aktion');
        $aktion->setAttribute('aktionart', 'CHANGE');
        $vt->appendChild($aktion);

        // Stand
        $updated = $prop->updated_at ?: $prop->created_at ?: now()->toDateTimeString();
        $this->addElement($dom, $vt, 'stand_vom', date('Y-m-d', strtotime($updated)));

        // Aktiv
        $this->addElement($dom, $vt, 'aktiv_von', date('Y-m-d', strtotime($prop->created_at ?? 'now')));
    }

    // ── Helper Methods ───────────────────────────────

    private function addElement(\DOMDocument $dom, \DOMElement $parent, string $tag, ?string $value): \DOMElement
    {
        $el = $dom->createElement($tag, htmlspecialchars($value ?? '', ENT_XML1));
        $parent->appendChild($el);
        return $el;
    }

    private function addCdataElement(\DOMDocument $dom, \DOMElement $parent, string $tag, string $value): \DOMElement
    {
        $el = $dom->createElement($tag);
        $el->appendChild($dom->createCDATASection($value));
        $parent->appendChild($el);
        return $el;
    }

    private function addBoolElement(\DOMDocument $dom, \DOMElement $parent, string $tag, bool $value, ?string $attr = null): void
    {
        $el = $dom->createElement($tag);
        if ($attr) {
            $el->setAttribute($attr, 'true');
        } else {
            $el->nodeValue = $value ? 'true' : 'false';
        }
        $parent->appendChild($el);
    }
}
