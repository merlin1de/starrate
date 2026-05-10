/**
 * StarRate E2E – Mobile-Viewport Virtualisierung
 *
 * Sichert ab, dass auf Mobile-Viewport-Breite (414×896, iPhone-Plus-ish)
 * die Grid-Virtualisierung aktiv ist und Scrollen den Render-Range
 * shiftet — die Logik die in jsdom-Vitest nicht abgedeckt ist
 * (clientWidth/Height = 0 → virtualEnabled=false → Fallback rendert alles).
 *
 * Voraussetzung: TEST_FOLDER hat ≥20 Bilder, sonst skippen die Tests
 * mit Log-Meldung. Anzahl der Spalten wird hier NICHT asserted — Cypress'
 * Viewport-Setting steuert window.innerWidth, aber NC's mobile UI/Sidebar-
 * Verhalten variiert; wichtig ist nur dass virtualEnabled triggert und
 * Range-Shift + Spacer-Logik funktionieren.
 */

import { login, openFolder, NC_URL, NC_USER, NC_PASS, TEST_FOLDER } from './helpers'

describe('Mobile Viewport Virtualisierung', () => {
  let totalImages = 0

  before(() => {
    login()
    cy.request({
      method: 'GET',
      url: `${NC_URL}/index.php/apps/starrate/api/images?path=${encodeURIComponent(TEST_FOLDER)}`,
      headers: { 'OCS-APIREQUEST': 'true' },
      auth: { user: NC_USER, pass: NC_PASS },
    }).then(resp => {
      totalImages = resp.body.images?.length || 0
      cy.log(`Test folder hat ${totalImages} Bilder`)
    })
  })

  beforeEach(() => {
    cy.viewport(414, 896)  // iPhone Plus, 2-Spalten-Layout
    cy.clearLocalStorage()
    login()
  })

  it('aktiviert Virtualisierung wenn Bilder den Mobile-Viewport übersteigen', () => {
    if (totalImages < 12) {
      cy.log(`Skip: nur ${totalImages} Bilder im Test-Folder, braucht ≥12 für Virtualisierung-Trigger`)
      return
    }
    openFolder()
    // Bei ≥12 Items auf 2-Spalten-Mobile (~6+ Reihen) geht das über den
    // initialen Render-Range hinaus — bottomSpacer muss existieren und
    // gerenderte Items < total.
    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').should('have.length.lessThan', totalImages)
    cy.get('.sr-grid__spacer').should('exist')
  })

  it('shiftet den Render-Range beim Scrollen', () => {
    if (totalImages < 20) {
      cy.log(`Skip: nur ${totalImages} Bilder, braucht ≥20 für sichtbares Range-Shift`)
      return
    }
    openFolder()
    // data-index des ersten gerenderten Items festhalten
    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first()
      .invoke('attr', 'data-index')
      .then(initialIdx => {
        // Weit genug scrollen, dass der Top-Buffer rausfällt
        // (BUFFER=2 Reihen × 163 ≈ 326px; 1500px = ~9 Reihen Scroll)
        cy.get('.sr-grid').scrollTo(0, 1500, { ensureScrollable: false })
        cy.wait(400)  // rAF + Vue render + observer

        cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').first()
          .invoke('attr', 'data-index')
          .then(newIdx => {
            // Wenn wir am Top starteten (idx=0): nach Scroll muss data-index > 0
            // Wenn schon initial verschoben (Loupe-Sync etc.): nicht regressionsfähig,
            // aber mindestens nicht negativ und Number-Parse muss klappen
            expect(parseInt(newIdx, 10)).to.be.gte(parseInt(initialIdx, 10))
            if (initialIdx === '0') {
              expect(parseInt(newIdx, 10)).to.be.greaterThan(0)
            }
          })
      })
  })

  it('topSpacer wächst nach Scrollen aus dem Top-Bereich', () => {
    if (totalImages < 20) {
      cy.log(`Skip: nur ${totalImages} Bilder, braucht ≥20`)
      return
    }
    openFolder()
    // Initial: visibleStartRow=0 → topSpacer wird via v-if NICHT gerendert
    // (es gibt nur den bottomSpacer im DOM, falls überhaupt einer nötig ist).
    // Nach Scroll > BUFFER × rowStride sollten zwei Spacer existieren (top + bottom).
    cy.get('.sr-grid').scrollTo(0, 1500, { ensureScrollable: false })
    cy.wait(400)
    cy.get('.sr-grid__spacer').should('have.length.gte', 2)
  })
})
