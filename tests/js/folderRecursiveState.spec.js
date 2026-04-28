import { describe, it, expect, beforeEach } from 'vitest'
import {
  readFolderState,
  writeFolderState,
  clearFolderState,
  clearAllFolderStates,
} from '../../src/utils/folderRecursiveState.js'

describe('folderRecursiveState', () => {

  beforeEach(() => {
    localStorage.clear()
  })

  // ── Read/Write Roundtrip ──────────────────────────────────────────────────

  it('liefert null für nicht gespeicherten Pfad', () => {
    expect(readFolderState('/Photos/2026')).toBeNull()
  })

  it('Roundtrip: write dann read liefert dieselben Werte', () => {
    writeFolderState('/Photos/2026', true, 2)
    expect(readFolderState('/Photos/2026')).toEqual({ recursive: true, depth: 2 })
  })

  it('verschiedene Pfade sind unabhängig', () => {
    writeFolderState('/A', true, 1)
    writeFolderState('/B', false, 3)
    expect(readFolderState('/A')).toEqual({ recursive: true, depth: 1 })
    expect(readFolderState('/B')).toEqual({ recursive: false, depth: 3 })
  })

  it('Re-Write überschreibt vorherigen Wert', () => {
    writeFolderState('/X', true, 1)
    writeFolderState('/X', false, 4)
    expect(readFolderState('/X')).toEqual({ recursive: false, depth: 4 })
  })

  // ── Clear ──────────────────────────────────────────────────────────────────

  it('clearFolderState entfernt den Eintrag', () => {
    writeFolderState('/Y', true, 2)
    clearFolderState('/Y')
    expect(readFolderState('/Y')).toBeNull()
  })

  it('clearAllFolderStates löscht alle Einträge', () => {
    writeFolderState('/A', true, 1)
    writeFolderState('/B', true, 2)
    clearAllFolderStates()
    expect(readFolderState('/A')).toBeNull()
    expect(readFolderState('/B')).toBeNull()
  })

  // ── Validation gegen kaputte Daten ────────────────────────────────────────

  it('clamped Depth auf 0-4 beim Schreiben', () => {
    writeFolderState('/A', true, 99)
    expect(readFolderState('/A').depth).toBe(4)
    writeFolderState('/A', true, -3)
    expect(readFolderState('/A').depth).toBe(0)
  })

  it('coerced recursive zu Boolean', () => {
    writeFolderState('/A', 'yes', 2)
    expect(readFolderState('/A').recursive).toBe(true)
    writeFolderState('/B', null, 2)
    expect(readFolderState('/B').recursive).toBe(false)
  })

  it('liefert sinnvolle Defaults bei manipulierten LocalStorage-Daten', () => {
    // Manuell kaputten Eintrag injizieren
    localStorage.setItem('starrate_folder_recursive_v1',
      JSON.stringify({ '/X': { recursive: 'truthy-string', depth: 'two' } }))
    const state = readFolderState('/X')
    expect(state.recursive).toBe(false)  // strikt true erwartet
    expect(state.depth).toBe(0)          // out-of-range fällt auf 0
  })

  it('liefert null bei kaputtem JSON in LocalStorage', () => {
    localStorage.setItem('starrate_folder_recursive_v1', '{not valid json')
    expect(readFolderState('/Any')).toBeNull()
  })

  // ── Edge Cases ─────────────────────────────────────────────────────────────

  it('ignoriert leeren oder fehlenden Pfad bei write', () => {
    writeFolderState('', true, 2)
    writeFolderState(null, true, 2)
    writeFolderState(undefined, true, 2)
    expect(readFolderState('')).toBeNull()
  })

  it('ignoriert leeren Pfad bei read', () => {
    expect(readFolderState('')).toBeNull()
    expect(readFolderState(null)).toBeNull()
    expect(readFolderState(undefined)).toBeNull()
  })

  // ── LRU-Eviction (Cap auf 50 Einträge) ────────────────────────────────────

  it('hält max 50 Einträge — der älteste wird beim 51. Write rausgeworfen', () => {
    for (let i = 0; i < 50; i++) {
      writeFolderState(`/folder${i}`, true, i % 5)
    }
    // Alle 50 da
    expect(readFolderState('/folder0')).not.toBeNull()
    expect(readFolderState('/folder49')).not.toBeNull()

    // Neuer Eintrag → /folder0 muss raus (ältester)
    writeFolderState('/folder50', true, 1)
    expect(readFolderState('/folder0')).toBeNull()
    expect(readFolderState('/folder49')).not.toBeNull()
    expect(readFolderState('/folder50')).not.toBeNull()
  })

  it('Re-Write eines existierenden Eintrags schiebt ihn ans Ende der Order', () => {
    for (let i = 0; i < 50; i++) {
      writeFolderState(`/folder${i}`, true, i % 5)
    }
    // /folder0 jetzt aktualisieren — sollte ans Ende rutschen
    writeFolderState('/folder0', true, 4)
    // Neuer Eintrag /folder50 → der jetzt ÄLTESTE (/folder1) muss raus,
    // /folder0 bleibt drin weil neu insertiert.
    writeFolderState('/folder50', true, 0)
    expect(readFolderState('/folder1')).toBeNull()
    expect(readFolderState('/folder0')).not.toBeNull()
    expect(readFolderState('/folder50')).not.toBeNull()
  })
})
