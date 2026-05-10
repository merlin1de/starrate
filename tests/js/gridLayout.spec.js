import { describe, it, expect } from 'vitest'
import {
  spacerHeightForRows,
  computeCompressionRatio,
  computeTopSpacerHeight,
  iterateViewportFirst,
} from '../../src/utils/gridLayout.js'

describe('spacerHeightForRows', () => {
  it('liefert 0 für n ≤ 0', () => {
    expect(spacerHeightForRows(0, 157, 6)).toBe(0)
    expect(spacerHeightForRows(-1, 157, 6)).toBe(0)
  })

  it('liefert N×rowHeight + (N-1)×gap (CSS-Grid-Layout-Match)', () => {
    expect(spacerHeightForRows(1, 157, 6)).toBe(157)        // keine Gap bei N=1
    expect(spacerHeightForRows(2, 157, 6)).toBe(157 * 2 + 6) // = 320
    expect(spacerHeightForRows(5, 157, 6)).toBe(157 * 5 + 6 * 4) // = 809
  })
})

describe('computeCompressionRatio', () => {
  it('liefert 1 wenn Container unter dem Cap', () => {
    expect(computeCompressionRatio(100_000, 350_000)).toBe(1)
    expect(computeCompressionRatio(0, 350_000)).toBe(1)
    expect(computeCompressionRatio(350_000, 350_000)).toBe(1)  // exakt gleich
  })

  it('liefert echtes Verhältnis wenn Container den Cap überschreitet', () => {
    // 7000 Bilder × Mobile (~570k) bei 350k Cap → ~1.63
    expect(computeCompressionRatio(570_000, 350_000)).toBeCloseTo(1.628, 2)
    expect(computeCompressionRatio(700_000, 350_000)).toBe(2)
  })

  it('liefert 1 bei kaputtem Cap (≤ 0)', () => {
    expect(computeCompressionRatio(100_000, 0)).toBe(1)
    expect(computeCompressionRatio(100_000, -1)).toBe(1)
  })
})

describe('computeTopSpacerHeight', () => {
  const baseArgs = {
    virtualEnabled: true,
    rowHeight: 157,
    rowStride: 163,
    gap: 6,
    bufferRows: 2,
  }

  it('liefert 0 wenn Virtualisierung deaktiviert ist (Fallback-Modus)', () => {
    expect(computeTopSpacerHeight({
      ...baseArgs,
      virtualEnabled: false,
      compressionRatio: 1.5,
      visibleStartRow: 10,
      scrollTop: 1000,
      logicalScrollTop: 1500,
    })).toBe(0)
  })

  describe('Compression = 1 (klassischer Modus)', () => {
    it('liefert spacerHeightForRows(visibleStartRow)', () => {
      expect(computeTopSpacerHeight({
        ...baseArgs,
        compressionRatio: 1,
        visibleStartRow: 0,
        scrollTop: 0,
        logicalScrollTop: 0,
      })).toBe(0)

      expect(computeTopSpacerHeight({
        ...baseArgs,
        compressionRatio: 1,
        visibleStartRow: 5,
        scrollTop: 1000,
        logicalScrollTop: 1000,
      })).toBe(spacerHeightForRows(5, 157, 6))  // 5×157 + 4×6 = 809
    })

    it('hängt nicht von scrollTop ab (nur von visibleStartRow)', () => {
      const a = computeTopSpacerHeight({
        ...baseArgs, compressionRatio: 1, visibleStartRow: 3, scrollTop: 500, logicalScrollTop: 500,
      })
      const b = computeTopSpacerHeight({
        ...baseArgs, compressionRatio: 1, visibleStartRow: 3, scrollTop: 1500, logicalScrollTop: 1500,
      })
      expect(a).toBe(b)
    })
  })

  describe('Compression > 1 (kontinuierlicher Modus)', () => {
    it('liefert 0 wenn visibleStartRow ≤ 0 (Top-Edge-Region)', () => {
      expect(computeTopSpacerHeight({
        ...baseArgs,
        compressionRatio: 1.6,
        visibleStartRow: 0,
        scrollTop: 100,
        logicalScrollTop: 160,
      })).toBe(0)
    })

    it('subtrahiert rowOffset von scrollTop für Sub-Row-Continuity', () => {
      // Bei einem Row-Tick (logicalScrollTop genau auf rowStride-Vielfachem):
      // rowOffset = 0, Spacer = scrollTop - 0 - bufferRows×rowStride
      const onTick = computeTopSpacerHeight({
        ...baseArgs,
        compressionRatio: 1.63,
        visibleStartRow: 5,
        scrollTop: 500,
        logicalScrollTop: 5 * 163,  // = 815, exakt auf row-Grenze
        rowStride: 163,
      })
      expect(onTick).toBe(500 - 0 - 2 * 163)  // = 174

      // Mitten in einer Reihe: rowOffset > 0, Spacer kleiner
      const midRow = computeTopSpacerHeight({
        ...baseArgs,
        compressionRatio: 1.63,
        visibleStartRow: 5,
        scrollTop: 550,
        logicalScrollTop: 5 * 163 + 50,  // = 865, 50px in die 6. Reihe rein
        rowStride: 163,
      })
      expect(midRow).toBe(550 - 50 - 2 * 163)  // = 174
    })

    it('clampt auf 0 wenn die Formel negativ würde (kleiner scrollTop)', () => {
      // scrollTop knapp über bufferRows×rowStride: Formel könnte negativ werden
      // wenn rowOffset größer als (scrollTop - bufferRows×rowStride) ist.
      const result = computeTopSpacerHeight({
        ...baseArgs,
        compressionRatio: 1.63,
        visibleStartRow: 1,
        scrollTop: 100,
        logicalScrollTop: 163,  // rowOffset = 0
        rowStride: 163,
      })
      // Formel: 100 - 0 - 326 = -226 → clamp auf 0
      expect(result).toBe(0)
    })

    it('Continuity-Check: Inhalt bewegt sich glatt um scrollTop-Δ minus rowOffset-Δ', () => {
      // Bei einem 1-Pixel-scrollTop-Increment innerhalb einer Reihe ändert sich
      // logicalScrollTop um compressionRatio (z.B. 1.6), rowOffset um 1.6, Spacer
      // um (1 - 1.6) = -0.6 → Items wandern netto 0.6 px nach oben relativ zum
      // Container, was kombiniert mit dem 1px Viewport-Versatz 1.6 px Bewegung
      // auf dem Screen ergibt — die gewollte Compression-Geschwindigkeit.
      const ratio = 1.6
      const scrollTopA = 1000
      const scrollTopB = 1001
      const logicalA   = scrollTopA * ratio
      const logicalB   = scrollTopB * ratio
      const a = computeTopSpacerHeight({
        ...baseArgs,
        compressionRatio: ratio,
        visibleStartRow: 8,  // weit genug vom Top weg
        scrollTop: scrollTopA,
        logicalScrollTop: logicalA,
      })
      const b = computeTopSpacerHeight({
        ...baseArgs,
        compressionRatio: ratio,
        visibleStartRow: 8,
        scrollTop: scrollTopB,
        logicalScrollTop: logicalB,
      })
      // Innerhalb derselben Reihe (kein Row-Tick):
      // Δ Spacer = (scrollTopB - scrollTopA) - (rowOffsetB - rowOffsetA)
      //         = 1 - 1.6 = -0.6
      expect(b - a).toBeCloseTo(-0.6, 5)
    })
  })
})

describe('iterateViewportFirst', () => {
  it('liefert Viewport-Items reverse-iter mit priority=true', () => {
    // Range 0..10, Viewport 4..7 (= Items 4, 5, 6)
    const result = [...iterateViewportFirst(0, 10, 4, 7)]
    const viewportItems = result.filter(([, p]) => p === true).map(([i]) => i)
    // Reverse-Iter: 6, 5, 4 (so unshift macht 4 ganz vorne in der Queue)
    expect(viewportItems).toEqual([6, 5, 4])
  })

  it('liefert Buffer-unten direkt nach Viewport, mit priority=false', () => {
    const result = [...iterateViewportFirst(0, 10, 4, 7)]
    // Nach den 3 Viewport-Items kommt Buffer-unten: 7, 8, 9 in Forward-Order
    expect(result.slice(3, 6)).toEqual([[7, false], [8, false], [9, false]])
  })

  it('liefert Buffer-oben zuletzt, mit priority=false', () => {
    const result = [...iterateViewportFirst(0, 10, 4, 7)]
    // Zuletzt Buffer-oben: 0, 1, 2, 3 in Forward-Order
    expect(result.slice(6)).toEqual([[0, false], [1, false], [2, false], [3, false]])
  })

  it('iteriert kompletten Range (jedes Item genau einmal)', () => {
    const result = [...iterateViewportFirst(0, 10, 4, 7)]
    const indices = result.map(([i]) => i).sort((a, b) => a - b)
    expect(indices).toEqual([0, 1, 2, 3, 4, 5, 6, 7, 8, 9])
  })

  it('kommt mit Range ohne Buffer-Above klar (Top der Liste)', () => {
    // visibleStart=0 → kein Buffer oben
    const result = [...iterateViewportFirst(0, 6, 0, 4)]
    expect(result).toEqual([
      [3, true], [2, true], [1, true], [0, true],   // Viewport reverse
      [4, false], [5, false],                        // Buffer unten
      // kein Buffer oben
    ])
  })

  it('kommt mit Range ohne Buffer-Below klar (Bottom der Liste)', () => {
    // vpEnd === renderEndIdx → kein Buffer unten
    const result = [...iterateViewportFirst(2, 7, 4, 7)]
    expect(result).toEqual([
      [6, true], [5, true], [4, true],   // Viewport reverse
      // kein Buffer unten
      [2, false], [3, false],            // Buffer oben
    ])
  })

  it('liefert nichts wenn Range leer ist', () => {
    expect([...iterateViewportFirst(5, 5, 5, 5)]).toEqual([])
  })

  it('Top-of-Viewport landet vorne in der Queue (unshift-Semantik)', () => {
    // Konsument-Simulation: priority=true → unshift, false → push
    const queue = []
    for (const [i, priority] of iterateViewportFirst(0, 10, 4, 7)) {
      if (priority) queue.unshift(i)
      else queue.push(i)
    }
    // Erwartete Reihenfolge in Queue: Viewport-Top zuerst (4), dann 5, 6,
    // dann Buffer-unten (7, 8, 9), dann Buffer-oben (0, 1, 2, 3)
    expect(queue).toEqual([4, 5, 6, 7, 8, 9, 0, 1, 2, 3])
  })
})
