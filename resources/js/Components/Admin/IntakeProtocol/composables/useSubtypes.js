// Subtypen pro object_type. Steuert welche Pills in Step 1 angezeigt werden
// und welche Folge-Felder später relevant sind.
export const SUBTYPES = {
  Haus: [
    'Einfamilienhaus', 'Doppelhaushälfte', 'Reihenhaus',
    'Zweifamilienhaus', 'Mehrfamilienhaus', 'Villa',
    'Bauernhaus', 'Stadthaus', 'Landhaus',
    'Ferienhaus', 'Berghaus', 'Sonstiges',
  ],
  Wohnung: [
    'Eigentumswohnung', 'Maisonette', 'Dachgeschoss',
    'Penthouse', 'Loft', 'Souterrain',
    'Terrassenwohnung', 'Gartenwohnung',
    'Studio/Einzimmer', 'Appartement', 'Sonstiges',
  ],
  Grundstück: [
    'Baugrund', 'Landwirtschaftlich', 'Gewerbegrund',
    'Wald/Forst', 'Freizeitgrund', 'Sonstiges',
  ],
  Gewerbe: [
    'Büro', 'Ladenfläche', 'Gastronomie', 'Hotel',
    'Lager', 'Produktion', 'Werkstatt', 'Praxis',
    'Anlageobjekt', 'Sonstiges',
  ],
};

export function useSubtypes(objectTypeRef) {
  return () => SUBTYPES[objectTypeRef.value] || [];
}
