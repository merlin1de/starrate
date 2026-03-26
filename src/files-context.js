/**
 * StarRate – Files Context
 *
 * Läuft auf jeder eingeloggten NC-Seite.
 * Speichert den NC-Files-Ordnerpfad in localStorage genau dann,
 * wenn der Nutzer auf den StarRate-Navigationslink klickt.
 * Kein Polling, kein pushState-Patching.
 */

const KEY = 'starrate_nc_path'

function extractDir() {
  const href = window.location.href
  if (!href.includes('/apps/files')) return null

  // NC speichert den echten Ordnerpfad immer in ?dir=
  // (Pfad-Segmente wie /files/82286 sind File-IDs, keine Ordnerpfade)
  const dir = new URLSearchParams(window.location.search).get('dir')
  if (dir && dir !== '/') return dir

  return null
}

// Nur beim Klick auf den StarRate-Link schreiben (kein Polling-Overhead)
document.addEventListener('click', function (e) {
  const link = e.target.closest('a[href]')
  if (!link) return
  if (!link.href.includes('apps/starrate')) return

  const dir = extractDir()
  if (dir) {
    localStorage.setItem(KEY, JSON.stringify({ dir, t: Date.now() }))
  } else {
    // Kein gültiger ?dir=-Pfad (z.B. Root, Datei-URL mit File-ID wie /files/82286,
    // oder eine andere NC-Seite) → stalen Wert löschen.
    // Ohne dieses removeItem würde StarRate beim nächsten Start einen veralteten
    // Ordnerpfad oder eine numerische File-ID öffnen.
    localStorage.removeItem(KEY)
  }
}, { capture: true })
