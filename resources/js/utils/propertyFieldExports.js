/**
 * Zentrale Mapping-Tabelle: Wohin wird jedes Property-Feld exportiert?
 *
 * Ziel-Kürzel:
 *   i = Immoji (indirekt Willhaben, ImmoScout24, ImmoWelt)
 *   w = SR-Homes Website (sr-homes.at)
 *   p = Kundenportal (kundenportal.sr-homes.at)
 *   l = Nur intern (nirgendwo exportiert)
 *
 * Quellen für die Mapping-Entscheidungen:
 *   - Immoji:   app/Services/ImmojiUploadService.php (mapPropertyToImmoji* Methoden)
 *   - Website:  app/Http/Controllers/WebsiteApiController.php (select-Liste in properties())
 *
 * Pflege-Hinweis: Bei Schema-Änderungen in einer der Backend-Quellen hier
 * manuell nachziehen. Fehlende Felder zeigen kein Icon (defensive default).
 */

export const FIELD_EXPORTS = {
  // === OBJEKT ===
  ref_id:              { targets: ['i', 'w'], tips: { i: 'Immoji: Objektnummer', w: 'Website: Referenz-ID' } },
  object_type:         { targets: ['i', 'w'], tips: { i: 'Immoji: Objekttyp', w: 'Website: Typ-Filter' } },
  object_subtype:      { targets: ['i'],      tips: { i: 'Immoji: Objekt-Subtyp' } },
  marketing_type:      { targets: ['i', 'w'], tips: { i: 'Immoji: Kauf/Miete', w: 'Website: Kauf/Miete-Filter' } },
  property_category:   { targets: ['w'],      tips: { w: 'Website: Kategorie (Bestand/Neubau)' } },
  project_name:        { targets: ['i', 'w'], tips: { w: 'Website: Projekt-Titel' } },
  title:               { targets: ['i'],      tips: { i: 'Immoji: Objekt-Titel' } },
  subtitle:            { targets: ['i'],      tips: { i: 'Immoji: Objekt-Untertitel' } },

  // === ADRESSE ===
  address:             { targets: ['i', 'w', 'p'] },
  house_number:        { targets: ['i'] },
  zip:                 { targets: ['i', 'w', 'p'] },
  city:                { targets: ['i', 'w', 'p'] },
  staircase:           { targets: ['i'] },
  door:                { targets: ['i'] },
  entrance:            { targets: ['i'] },
  address_floor:       { targets: ['i'] },
  latitude:            { targets: ['i'] },
  longitude:           { targets: ['i'] },

  // === ZUORDNUNGEN ===
  broker_id:           { targets: ['i', 'w'], tips: { i: 'Immoji: realtyManager', w: 'Website: Makler-Name' } },
  property_manager_id: { targets: ['l'],      tips: { l: 'Nur intern (HV-Kontakt)' } },
  property_manager:    { targets: ['i'],      tips: { i: 'Immoji: Hausverwaltung (legacy)' } },
  builder_company:     { targets: ['i'],      tips: { i: 'Immoji: Bauträger' } },

  // === STATUS ===
  status:              { targets: ['i'],      tips: { i: 'Immoji: realtyStatus (Aktiv/Inaktiv/Verkauft)' } },
  realty_status:       { targets: ['i', 'w'] },
  inserat_since:       { targets: ['l'],      tips: { l: 'Nur intern (Inserat-Beginn)' } },
  available_from:      { targets: ['i', 'w'], tips: { w: 'Website: Beziehbar ab in Details' } },
  available_text:      { targets: ['i'] },
  construction_start:  { targets: ['l'] },
  construction_end:    { targets: ['w'],      tips: { w: 'Website: Fertigstellung in Details' } },
  closing_date:        { targets: ['i'] },
  sold_at:             { targets: ['w'] },

  // === BAU & ZUSTAND ===
  construction_type:   { targets: ['i', 'w'], tips: { w: 'Website: Bauart in Details' } },
  construction_year:   { targets: ['i', 'w'] },
  year_renovated:      { targets: ['w'] },
  realty_condition:    { targets: ['i', 'w'], tips: { w: 'Website: Objekt-Zustand in Details' } },
  quality:             { targets: ['w'],      tips: { w: 'Website: Qualität in Details' } },
  ownership_type:      { targets: ['w'],      tips: { w: 'Website: Eigentumsform in Details' } },
  total_units:         { targets: ['i', 'w'], tips: { i: 'Immoji: Residentialeinheiten', w: 'Website: Wohneinheiten in Details' } },
  unit_count:          { targets: ['i', 'w'], tips: { w: 'Website: Wohneinheiten-Zähler' } },

  // === RÄUME ===
  rooms_amount:        { targets: ['i', 'w'] },
  bedrooms:            { targets: ['i'] },
  bathrooms:           { targets: ['i', 'w'] },
  toilets:             { targets: ['i'] },
  floor_number:        { targets: ['l'] },
  floor_count:         { targets: ['w'],      tips: { w: 'Website: Stockwerke in Details' } },

  // === PREISE ===
  purchase_price:      { targets: ['i', 'w'], tips: { i: 'Immoji: Kaufpreis', w: 'Website: Preis' } },
  rental_price:        { targets: ['i', 'w'] },
  rent_warm:           { targets: ['l'] },
  rent_deposit:        { targets: ['l'] },
  price_per_m2:        { targets: ['l'],      tips: { l: 'Nur intern (Berechnung)' } },
  parking_price:       { targets: ['l'] },

  // === BETRIEBSKOSTEN (mtl.) — gehen auf Website und Immoji ===
  operating_costs:      { targets: ['i', 'w'], tips: { w: 'Website: Betriebskosten-Aufschlüsselung' } },
  heating_costs:        { targets: ['i', 'w'], tips: { w: 'Website: Heizkosten-Zeile' } },
  warm_water_costs:     { targets: ['i', 'w'], tips: { w: 'Website: Warmwasser-Zeile' } },
  cooling_costs:        { targets: ['i', 'w'], tips: { w: 'Website: Kühlung-Zeile' } },
  maintenance_reserves: { targets: ['i', 'w'], tips: { w: 'Website: Rücklage-Zeile' } },
  admin_costs:          { targets: ['i', 'w'], tips: { w: 'Website: Verwaltungskosten-Zeile' } },
  elevator_costs:       { targets: ['i', 'w'], tips: { w: 'Website: Aufzugskosten-Zeile' } },
  parking_costs_monthly:{ targets: ['i', 'w'], tips: { w: 'Website: Stellplatzkosten-Zeile' } },
  other_costs:          { targets: ['i', 'w'], tips: { w: 'Website: Sonstige Kosten-Zeile' } },
  monthly_costs:        { targets: ['w'],      tips: { w: 'Website: Gesamtsumme (Override)' } },

  // === PROVISIONEN ===
  // INTERN: Unsere Verkäufer-Provision — nur Cockpit/Übersicht, NICHT exportiert.
  commission_percent:       { targets: ['l'], tips: { l: 'Nur intern (Übersicht-Kalkulation)' } },
  commission_total:         { targets: ['l'], tips: { l: 'Nur intern (Gesamtsumme unserer Provision)' } },
  commission_note:          { targets: ['l'], tips: { l: 'Nur intern (Provisionsnotiz)' } },
  // ÖFFENTLICH: Käufer-Provision in % — geht auf Website + Immoji/Portale.
  buyer_commission_percent: { targets: ['i', 'w'], tips: { i: 'Immoji: Käufer-Provision', w: 'Website: Nebenkosten-Box' } },
  // Provisionsfrei-Flag — geht an Immoji/Portale (commissionPaidBySeller).
  buyer_commission_free:    { targets: ['i'] },
  // Legacy-Felder (nicht mehr in der UI, aber noch in der DB erhalten).
  commission_makler:        { targets: ['l'], tips: { l: 'Legacy — Berechnung nutzt % vom Kaufpreis' } },
  buyer_commission_text:    { targets: ['l'] },

  // === STEUERN/FEES ===
  land_register_fee_pct:    { targets: ['l'] },
  land_transfer_tax_pct:    { targets: ['l'] },
  contract_fee_pct:         { targets: ['l'] },

  // === FLÄCHEN ===
  living_area:         { targets: ['i', 'w'] },
  free_area:           { targets: ['i', 'w'] },
  realty_area:         { targets: ['i'] },
  total_area:          { targets: ['w'] },
  office_space:        { targets: ['i'] },
  area_balcony:        { targets: ['i'] },
  area_terrace:        { targets: ['i'] },
  area_garden:         { targets: ['i'] },
  area_loggia:         { targets: ['i'] },
  area_basement:       { targets: ['i'] },
  area_garage:         { targets: ['l'] },

  // === PARKEN ===
  garage_spaces:       { targets: ['w'] },
  parking_spaces:      { targets: ['w'] },
  parking_type:        { targets: ['l'] },

  // === ENERGIE ===
  energy_certificate:  { targets: ['i', 'w'] },
  heating_demand_value:{ targets: ['i', 'w'], tips: { w: 'Website: HWB in Details' } },
  heating_demand_class:{ targets: ['i', 'w'], tips: { w: 'Website: Energieklasse in Details' } },
  energy_efficiency_value: { targets: ['i', 'w'], tips: { w: 'Website: fGEE in Details' } },
  energy_primary_source:   { targets: ['w'],      tips: { w: 'Website: Primärenergie in Details' } },
  energy_valid_until:      { targets: ['l'] },
  energy_type:             { targets: ['l'] },
  heating:             { targets: ['i'] },

  // === AUSSTATTUNG (Booleans) ===
  has_balcony:         { targets: ['i', 'w'] },
  has_terrace:         { targets: ['i', 'w'] },
  has_loggia:          { targets: ['i', 'w'] },
  has_garden:          { targets: ['i', 'w'] },
  has_basement:        { targets: ['i', 'w'] },
  has_cellar:          { targets: ['l'] },
  has_elevator:        { targets: ['i', 'w'] },
  has_fitted_kitchen:  { targets: ['i', 'w'] },
  has_air_conditioning:{ targets: ['i', 'w'] },
  has_pool:            { targets: ['i', 'w'] },
  has_sauna:           { targets: ['i', 'w'] },
  has_fireplace:       { targets: ['w'] },
  has_alarm:           { targets: ['w'] },
  has_barrier_free:    { targets: ['w'] },
  has_guest_wc:        { targets: ['w'] },
  has_storage_room:    { targets: ['w'] },
  has_washing_connection:  { targets: ['l'] },
  has_photovoltaik:    { targets: ['w'] },
  has_charging_station:{ targets: ['w'] },

  // === INNENEIGENSCHAFTEN ===
  kitchen_type:        { targets: ['l'] },
  flooring:            { targets: ['w'],      tips: { w: 'Website: Bodenbelag in Details' } },
  bathroom_equipment:  { targets: ['l'] },
  orientation:         { targets: ['l'] },
  furnishing:          { targets: ['i', 'w'], tips: { w: 'Website: Möblierung in Details' } },
  condition_note:      { targets: ['w'] },
  common_areas:        { targets: ['w'] },

  // === BESCHREIBUNG ===
  realty_description:  { targets: ['i', 'w'], tips: { i: 'Immoji: Hauptbeschreibung', w: 'Website: Objektbeschreibung' } },
  highlights:          { targets: ['w'] },
  ad_tag:              { targets: ['i'] },
  internal_rating:     { targets: ['l'] },

  // === MEDIEN ===
  main_image_id:       { targets: ['w'] },
  website_gallery_ids: { targets: ['w'] },
  external_image_url:  { targets: ['w'] },
};

const TARGET_NAMES = {
  i: 'Immoji (Inserats-Portale)',
  w: 'SR-Homes Website',
  p: 'Kundenportal',
  l: 'Nur intern',
};

/**
 * Liefert { icons: string[], tooltip: string } für ein Feld.
 * icons ist die Liste der Ziel-Kürzel (i/w/p/l).
 * Leeres icons-Array bedeutet: kein Badge anzeigen.
 */
export function visForField(key) {
  const entry = FIELD_EXPORTS[key];
  if (!entry || !entry.targets?.length) {
    return { icons: [], tooltip: '' };
  }
  return {
    icons: entry.targets,
    tooltip: buildTooltip(entry),
  };
}

function buildTooltip(entry) {
  return entry.targets.map(t => {
    const specific = entry.tips?.[t];
    return specific || TARGET_NAMES[t];
  }).join(' · ');
}
