/**
 * StarRate – Cypress End-to-End Tests
 *
 * Voraussetzungen:
 *   - Nextcloud läuft auf http://localhost:8080
 *   - App ist installiert und aktiviert
 *   - Testbenutzer "admin" / "admin" existiert
 *   - Testordner "/Fotos/E2E-Test" enthält mindestens 5 JPEG-Bilder
 */

const NC_URL  = Cypress.env('NC_URL')  || 'http://localhost:8080'
const NC_USER = Cypress.env('NC_USER') || 'admin'
const NC_PASS = Cypress.env('NC_PASS') || 'admin'
const APP_URL = `${NC_URL}/apps/starrate`

// ResizeObserver-Fehler ignorieren (harmloser Browser-Bug)
Cypress.on('uncaught:exception', (err) => {
  if (err.message.includes('ResizeObserver loop')) return false
})

// ── Hilfsfunktionen ────────────────────────────────────────────────────────

// cy.session() cached login — schnell ab dem 2. Test
function login() {
  cy.session([NC_USER, NC_PASS], () => {
    cy.visit(`${NC_URL}/login`)
    cy.get('#user').clear().type(NC_USER)
    cy.get('#password').clear().type(NC_PASS)
    cy.get('[type=submit]').click()
    cy.url().should('include', '/apps/')
  })
}

function openFolder(path = '/Fotos/E2E-Test') {
  cy.visit(`${APP_URL}/#/folder${path}`)
  cy.get('.sr-grid', { timeout: 10000 }).should('be.visible')
  cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
    .should('have.length.greaterThan', 0)
}

// ── Test-Suite ─────────────────────────────────────────────────────────────

describe('StarRate E2E', () => {

  beforeEach(() => {
    cy.clearLocalStorage() // verhindert stale Filter-State aus vorigen Läufen
    login()
  })

  // ── 1. Bild öffnen → Stern setzen → Filter → nur bewertete sichtbar ────────

  describe('Bewertungs-Workflow', () => {

    it('setzt Sternebewertung und filtert danach', () => {
      openFolder()

      // Rating 4 auf erstes Bild setzen (force: hover-overlay hat pointer-events:none in headless)
      cy.get('.sr-grid__item').first().find('.sr-stars__star').eq(3).click({ force: true })

      // Toast erscheint
      cy.get('.sr-toast--success', { timeout: 5000 }).should('be.visible')

      // Filter auf ≥4★ setzen (Operator ≥ aktivieren, dann 4-Stern-Pill = Index 1 in [5,4,3,2,1])
      cy.get('.sr-filterbar__op').contains('≥').click()
      cy.get('.sr-filterbar__pill--star').eq(1).click()

      // Nur noch bewertete Bilder sichtbar
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').each($item => {
        cy.wrap($item).find('.sr-grid__info-stars .sr-stars__star--filled')
          .should('have.length.greaterThan', 3)
      })

      // Filter zurücksetzen
      cy.get('.sr-filterbar__reset').click()
      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)')
        .should('have.length.greaterThan', 1)
    })

    it('setzt Farbmarkierung und filtert nach Farbe', () => {
      openFolder()

      // Green auf zweites Bild setzen
      cy.get('.sr-grid__item').eq(1).find('.sr-color-label__dot--green').click({ force: true })
      cy.get('.sr-toast--success').should('be.visible')

      // Nach Grün filtern (colordot: Red=0, Yellow=1, Green=2)
      cy.get('.sr-filterbar__colordot').eq(2).click()

      cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)').each($item => {
        cy.wrap($item).find('.sr-grid__info-color--green').should('exist')
      })

      cy.get('.sr-filterbar__reset').click()
    })
  })

  // ── 2. Zoom → 100% → Pan → zurück zu Fit ─────────────────────────────────

  describe('Lupenansicht Zoom & Pan', () => {

    it('öffnet Lupenansicht und testet Zoom', () => {
      openFolder()

      // Lupenansicht öffnen
      cy.get('.sr-grid__item').first().dblclick()
      cy.get('.sr-loupe', { timeout: 5000 }).should('be.visible')

      // Initial: Fit
      cy.get('.sr-loupe__zoom-level').should('contain', 'Eingepasst')

      // Doppelklick → 100%
      cy.get('.sr-loupe').dblclick()
      cy.get('.sr-loupe__zoom-level').should('not.contain', 'Eingepasst')

      // Nochmal Doppelklick → Fit
      cy.get('.sr-loupe').dblclick()
      cy.get('.sr-loupe__zoom-level').should('contain', 'Eingepasst')

      // Tastatur: + zoomt rein (Loupe nutzt document.addEventListener → body)
      cy.get('body').type('+')
      cy.get('.sr-loupe__zoom-level').should('contain', '%')

      // Leertaste → Fit
      cy.get('body').type(' ')
      cy.get('.sr-loupe__zoom-level').should('contain', 'Eingepasst')

      // Esc wenn nicht fit → zurück zu fit
      cy.get('.sr-loupe').dblclick() // zoom rein
      cy.get('.sr-loupe__zoom-level').should('not.contain', 'Eingepasst')
      cy.get('body').type('{esc}')
      cy.get('.sr-loupe__zoom-level').should('contain', 'Eingepasst')

      // Esc wenn fit → Lupe schließen
      cy.get('body').type('{esc}')
      cy.get('.sr-grid').should('be.visible')
    })
  })

  // ── 3. Shift+Klick → Stapel-Bewertung → Toast-Bestätigung ────────────────

  describe('Stapel-Bewertung', () => {

    it('markiert Bereich per Shift+Klick und bewertet alle', () => {
      openFolder()

      // Ersten Klick ohne Modifier (Anker setzen)
      cy.get('.sr-grid__item').first().click()

      // Shift+Klick auf 4. Bild → Indizes 0–3 markiert
      cy.get('.sr-grid__item').eq(3).click({ shiftKey: true })

      // SelectionBar erscheint
      cy.get('.sr-selbar', { timeout: 3000 }).should('be.visible')
      cy.get('.sr-selbar__count').should('contain', '4')

      // ★★★ setzen
      cy.get('.sr-selbar__btn--star').eq(3).click() // Rating 3

      // Toast-Bestätigung
      cy.get('.sr-toast--success', { timeout: 5000 }).should('contain', '4')

      // SelectionBar bleibt sichtbar bis Escape
      cy.get('.sr-selbar').should('be.visible')

      // Escape hebt Auswahl auf
      cy.get('body').type('{esc}')
      cy.get('.sr-selbar').should('not.exist')
    })
  })

  // ── 4. Gast-Galerie direkt per Token aufrufen ────────────────────────────
  // Share-UI (Token erstellen) ist noch nicht implementiert.
  // Dieser Test setzt einen manuell erstellten Token voraus (NC_SHARE_TOKEN env).

  describe('Gast-Freigabe', () => {

    const shareToken = Cypress.env('NC_SHARE_TOKEN')

    it('öffnet Gast-Galerie ohne Login und bewertet', () => {
      if (!shareToken) {
        cy.log('NC_SHARE_TOKEN nicht gesetzt — Test übersprungen')
        return
      }

      cy.visit(`${NC_URL}/logout`)
      cy.wait(1000)

      cy.visit(`${APP_URL}/guest/${shareToken}`)
      cy.get('.sr-guest', { timeout: 10000 }).should('be.visible')
      cy.get('.sr-guest__item', { timeout: 10000 }).should('have.length.greaterThan', 0)

      cy.get('.sr-guest__item').first().find('.sr-stars__star').eq(4).click()
      cy.get('.sr-guest__toast', { timeout: 5000 }).should('contain', 'Bewertung')

      login()
    })
  })

  // ── 6. Multi-User: Benutzer A bewertet, Benutzer B sieht Bewertung ────────

  describe('Multi-User Bewertung', () => {

    const USER_B     = Cypress.env('NC_USER_B') || 'user2'
    const PASS_B     = Cypress.env('NC_PASS_B') || 'user2'
    const SHARED_PATH = '/Fotos/Shared'

    it('Benutzer A setzt Bewertung', function() {
      if (!Cypress.env('NC_MULTI_USER')) { cy.log('NC_MULTI_USER nicht gesetzt — Test übersprungen'); this.skip(); return }
      // Bereits als User A eingeloggt
      openFolder(SHARED_PATH)
      cy.get('.sr-grid__item').first().find('.sr-stars__star').eq(4).click({ force: true })
      cy.get('.sr-toast--success').should('be.visible')
    })

    it('Benutzer B sieht die Bewertung von Benutzer A via API', function() {
      if (!Cypress.env('NC_MULTI_USER')) { cy.log('NC_MULTI_USER nicht gesetzt — Test übersprungen'); this.skip(); return }
      // NC Collaborative Tags sind geteilt → via API prüfen
      cy.request({
        url:    `${NC_URL}/apps/starrate/api/images?path=${SHARED_PATH}`,
        method: 'GET',
        headers: { 'OCS-APIRequest': 'true' },
        auth:   { user: USER_B, pass: PASS_B },
      }).then(resp => {
        const images = resp.body?.images ?? []
        const rated  = images.filter(img => img.rating > 0)
        expect(rated.length).to.be.greaterThan(0)
      })
    })
  })
})
