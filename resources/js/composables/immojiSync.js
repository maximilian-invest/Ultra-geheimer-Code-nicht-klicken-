import { ref } from 'vue'

/**
 * Globaler Immoji-Sync-Status.
 *
 * Zustand lebt im Modul-Scope, nicht in einer Component, damit der
 * Sync-Indicator Navigation zwischen Tabs/Pages ueberlebt. Der User
 * kann waehrend eines laufenden Uploads beliebig anderswo arbeiten —
 * der `await fetch` laeuft im Browser-Event-Loop weiter und triggert
 * beim Resolve den Indicator unten rechts.
 *
 * Drei Phasen:
 *   - idle      → kein Indicator
 *   - active    → Spinner-Toast unten rechts (kann NICHT weggeklickt werden,
 *                 verschwindet erst nach Abschluss)
 *   - result    → Erfolg/Fehler-Toast (MUSS aktiv weggeklickt werden, damit
 *                 der Makler sieht dass der Hintergrund-Sync fertig ist)
 */
export const immojiSyncState = ref({
  active: false,
  propertyId: null, // null wenn idle; sonst die ID des aktuell laufenden Sync-Targets
  message: '',
  startedAt: null,
  result: null, // { success, message, propertyId, propertyTitle }
})

/**
 * Startet einen Immoji-Push fire-and-forget. Gibt das Promise zurueck,
 * damit der Caller optional auf Abschluss reagieren kann (z.B. lokalen
 * Property-State aktualisieren) — aber das Promise NICHT awaiten zu
 * muessen ist der ganze Punkt.
 *
 * @param {Object} opts
 * @param {Number} opts.propertyId
 * @param {String} opts.propertyTitle  Anzeige-Name fuer den Toast
 * @param {Boolean} opts.force         force_full_sync flag
 * @param {String} opts.apiUrl         z.B. API.value (mit ?key=...)
 * @param {Function} [opts.onComplete] Callback(d) nach Abschluss; d ist die Server-Response oder null bei Fehler
 * @returns {Promise<Object|null>}
 */
export function startImmojiSync({ propertyId, propertyTitle, force, apiUrl, onComplete }) {
  immojiSyncState.value = {
    active: true,
    propertyId,
    message: propertyTitle || `Property #${propertyId}`,
    startedAt: Date.now(),
    result: null,
  }

  return fetch(apiUrl + '&action=immoji_push', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      property_id: propertyId,
      force_full_sync: !!force,
      dry_run: false,
    }),
  })
    .then((r) => r.json())
    .then((d) => {
      immojiSyncState.value = {
        active: false,
        propertyId: null,
        message: '',
        startedAt: null,
        result: {
          success: !!d.success,
          message: d.message || (d.success ? 'Sync erfolgreich' : 'Sync fehlgeschlagen'),
          propertyId,
          propertyTitle,
          immoji_id: d.immoji_id || null,
          action: d.action || null,
        },
      }
      if (typeof onComplete === 'function') {
        try { onComplete(d) } catch (e) { console.error('onComplete callback failed', e) }
      }
      return d
    })
    .catch((e) => {
      immojiSyncState.value = {
        active: false,
        propertyId: null,
        message: '',
        startedAt: null,
        result: {
          success: false,
          message: e?.message || 'Upload fehlgeschlagen',
          propertyId,
          propertyTitle,
        },
      }
      if (typeof onComplete === 'function') {
        try { onComplete(null) } catch (err) { console.error('onComplete callback failed', err) }
      }
      return null
    })
}

export function dismissImmojiResult() {
  immojiSyncState.value = {
    active: false,
    propertyId: null,
    message: '',
    startedAt: null,
    result: null,
  }
}
