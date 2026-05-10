/**
 * Pure helpers for the virtualized grid layout & thumbnail-loading queue.
 *
 * Extracted from GridView.vue so the Math is testable in isolation —
 * jsdom liefert keine echten Layout-Werte, was die Component-Tests im
 * virtualEnabled-Pfad nicht abdeckt. Die hier exportierten Funktionen
 * sind reine Berechnungen ohne DOM-Zugriff und decken die zentralen
 * Algorithmen (Compression-Ratio, kontinuierliche topSpacer-Formel,
 * Viewport-First-Iteration) testbar ab.
 */

/**
 * Höhe für N nicht-gerenderte Reihen oberhalb/unterhalb des Viewports —
 * exakt die CSS-Grid-Layout-Höhe N×rowHeight plus (N-1)×Gap (kein Edge-Gap).
 */
export function spacerHeightForRows(n, rowHeight, gap) {
  if (n <= 0) return 0
  return n * rowHeight + (n - 1) * gap
}

/**
 * Compression-Ratio: wenn der rechnerische Container höher wäre als der
 * physische Cap, mappt scrollTop linear auf alle Items (Ratio > 1). Bei
 * kleinen Listen unter dem Cap: Ratio = 1, kein Mapping.
 */
export function computeCompressionRatio(fullLogicalHeight, maxPhysicalHeight) {
  if (maxPhysicalHeight <= 0) return 1
  return Math.max(1, fullLogicalHeight / maxPhysicalHeight)
}

/**
 * Top-Spacer-Höhe für den virtualisierten Grid.
 *
 * Zwei Modi:
 *   - Compression-Ratio = 1 (kleine Listen):
 *     klassische Spacer-Höhe via spacerHeightForRows(visibleStartRow).
 *   - Compression aktiv (Ratio > 1):
 *     kontinuierliches Sub-Row-Mapping. Die erste sichtbare Reihe
 *     (visibleStartRow + bufferRows) liegt mit ihrer Top-Kante genau
 *     `rowOffset` Pixel über der Viewport-Oberkante, wobei rowOffset =
 *     logicalScrollTop mod rowStride. Der Spacer = scrollTop - rowOffset
 *     - bufferRows × rowStride; clampt auf 0 nahe dem Top-Edge.
 *
 * Liefert 0 in folgenden Fällen:
 *   - Virtualisierung nicht aktiv (Layout nicht gemessen)
 *   - visibleStartRow ≤ 0 (kein Spacer nötig vor erster Reihe)
 *   - Compression-Formel landet im negativen Bereich (Top-Edge-Region)
 */
export function computeTopSpacerHeight({
  virtualEnabled,
  compressionRatio,
  visibleStartRow,
  rowHeight,
  rowStride,
  gap,
  scrollTop,
  logicalScrollTop,
  bufferRows,
}) {
  if (!virtualEnabled) return 0
  if (compressionRatio === 1) {
    return spacerHeightForRows(visibleStartRow, rowHeight, gap)
  }
  if (visibleStartRow <= 0) return 0
  const rowOffset = logicalScrollTop % rowStride
  return Math.max(0, scrollTop - rowOffset - bufferRows * rowStride)
}

/**
 * Iteration über den Render-Range in Viewport-First-Reihenfolge.
 *
 * Yields [index, priority] pairs:
 *   1. Viewport-Items in Reverse-Reihenfolge (wenn der Konsument unshift't,
 *      landet das Top-of-Viewport ganz vorne).
 *   2. Buffer-unten in Forward-Reihenfolge (push behält Top-Down-Order).
 *   3. Buffer-oben in Forward-Reihenfolge (least likely, push ans Ende).
 *
 * priority ist `true` für Viewport-Items, `false` für Buffer. Konsumenten
 * (siehe enqueueThumb in GridView) können das in unshift-vs-push übersetzen.
 *
 * @param renderStartIdx Erstes gerendertes Item (inklusive)
 * @param renderEndIdx   Letztes gerendertes Item + 1 (exklusive)
 * @param vpStart        Erstes Viewport-Item (inklusive, ≥ renderStartIdx)
 * @param vpEnd          Letztes Viewport-Item + 1 (exklusive, ≤ renderEndIdx)
 */
export function* iterateViewportFirst(renderStartIdx, renderEndIdx, vpStart, vpEnd) {
  for (let i = vpEnd - 1; i >= vpStart; i--) yield [i, true]
  for (let i = vpEnd; i < renderEndIdx; i++) yield [i, false]
  for (let i = renderStartIdx; i < vpStart; i++) yield [i, false]
}
