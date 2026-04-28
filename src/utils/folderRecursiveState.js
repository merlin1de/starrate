/**
 * Per-Folder-Memory für die rekursive Ansicht.
 *
 * Speichert pro Folder-Pfad das zuletzt gewählte recursive/depth-Setting in
 * localStorage. Damit kommt der User beim Wiederbetreten desselben Folders in
 * den gleichen View zurück, ohne dass er global oder per Settings nachjustieren
 * muss. URL-Query-Params toppen weiterhin (Sharing-Use-Case).
 *
 * Hierarchie der Quellen (höchste Priorität zuerst):
 *   1. URL-Query (?recursive=…&depth=…)
 *   2. localStorage (dieses Modul)
 *   3. Personal Settings Default
 *
 * Cap: 50 Einträge. Beim Überlauf werden die ältesten herausgeworfen
 * (insertion-order). Dadurch bleibt localStorage klein, ohne Komfort-Verlust
 * für den realistischen Workflow.
 */

const STORAGE_KEY = 'starrate_folder_recursive_v1'
const MAX_ENTRIES = 50

/**
 * Lade die gesamte Map. Defensiv gegen kaputte/fehlende Daten.
 * @returns {Object<string, {recursive: boolean, depth: number}>}
 */
function loadMap() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw)
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch {
    // Quota, Privacy-Mode, kaputtes JSON — alles gleich behandelt
    return {}
  }
}

/**
 * Speichere die Map. Trimmt auf MAX_ENTRIES (älteste raus).
 */
function saveMap(map) {
  try {
    const keys = Object.keys(map)
    if (keys.length > MAX_ENTRIES) {
      // Älteste Insertion-Order-Einträge droppen
      const trimmed = {}
      const keepKeys = keys.slice(keys.length - MAX_ENTRIES)
      for (const k of keepKeys) trimmed[k] = map[k]
      map = trimmed
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify(map))
  } catch {
    // Quota/Privacy-Mode — silent fail, Memory-Verhalten degradiert auf
    // settings-default, aber app läuft weiter.
  }
}

/**
 * Lese den gespeicherten State für einen Folder-Pfad.
 * @param {string} path — z.B. '/Photos/2026'
 * @returns {{recursive: boolean, depth: number} | null}
 */
export function readFolderState(path) {
  if (!path) return null
  const map = loadMap()
  const entry = map[path]
  if (!entry) return null
  // Validation gegen kaputte/manipulierte Werte
  const recursive = entry.recursive === true
  const depth = Number.isInteger(entry.depth) && entry.depth >= 0 && entry.depth <= 4
    ? entry.depth
    : 0
  return { recursive, depth }
}

/**
 * Speichere den State für einen Folder-Pfad. Re-Write desselben Keys schiebt
 * ihn ans Ende der Insertion-Order (jüngster Eintrag zuletzt) — wichtig für
 * die Eviction-Logik beim Cap-Überlauf.
 * @param {string} path
 * @param {boolean} recursive
 * @param {number} depth — 0..4
 */
export function writeFolderState(path, recursive, depth) {
  if (!path) return
  const map = loadMap()
  // Re-Insert um Insertion-Order zu erneuern
  delete map[path]
  map[path] = {
    recursive: !!recursive,
    depth: Number.isInteger(depth) ? Math.max(0, Math.min(4, depth)) : 0,
  }
  saveMap(map)
}

/**
 * Entferne den State für einen Folder-Pfad (z.B. wenn der User die Default-
 * Konfig wiederherstellen will). Aktuell nicht aus der UI aufrufbar, aber
 * für künftige „Reset"-Aktionen vorbereitet.
 */
export function clearFolderState(path) {
  if (!path) return
  const map = loadMap()
  if (path in map) {
    delete map[path]
    saveMap(map)
  }
}

/**
 * Lösche alle gespeicherten Folder-States. Für Logout-Cleanup oder
 * Diagnose-Zwecke.
 */
export function clearAllFolderStates() {
  try { localStorage.removeItem(STORAGE_KEY) } catch { /* ignore */ }
}
