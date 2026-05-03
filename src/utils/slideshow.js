/**
 * Diashow-Konstanten — single source of truth für Frontend.
 * Backend-Spiegel: lib/Settings/UserSettings.php::SLIDESHOW_INTERVALS
 */

export const SLIDESHOW_INTERVALS = [1, 2, 3, 4, 5, 7, 10, 15, 30]

export const SLIDESHOW_DEFAULT_SEC = 4

/** localStorage-Key, NUR für Gast-Modus (eigener Namespace, kein Owner-Konflikt). */
export const SLIDESHOW_GUEST_LS_KEY = 'starrate_guest_slideshow_interval'

/**
 * @param {*} v Roher Wert (z.B. aus localStorage, URL, Form)
 * @returns {boolean}
 */
export function isValidSlideshowInterval(v) {
  return typeof v === 'number' && SLIDESHOW_INTERVALS.includes(v)
}
