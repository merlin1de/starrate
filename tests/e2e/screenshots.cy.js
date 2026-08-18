/**
 * StarRate – Marketing-Screenshots (Store + README).
 *
 * KEIN Test: fährt die aktuelle UI mit dem kuratierten Demo-Ordner /Portraits an
 * und schießt viewport-Screenshots in Store-Größe (1840x1300). Läuft NICHT im
 * normalen specPattern (Seed-abhängig) — nur manuell auf einer Wegwerf-Instanz.
 *
 * Seed (einmalig auf der Testinstanz, User `test`):
 *   1. /Portraits mit ~19 schönen JPGs (CAM_*.jpg) füllen (WebDAV PUT).
 *   2. occ files:scan test --path=test/files/Portraits
 *   3. Ratings/Farben/Pick setzen: POST /apps/starrate/api/rating/{fileId}
 *      (Basic-Auth, Body {"rating":N,"color":"Red|Yellow|Green|Blue|Purple","pick":"pick|reject"}).
 *   4. Previews wärmen: GET /apps/starrate/api/thumbnail/{id} + /api/preview/{id}.
 *   5. Konsistente Sprache erzwingen: occ config:system:set force_language --value=de
 *      (danach wieder entfernen!).
 *
 * Lauf (großes Fenster via separater Config erzwingen, sonst cappt der Headless-
 * Browser bei 1280px):
 *   docker compose -f docker/cypress.yml run --rm cypress \
 *     --browser chrome --config-file cypress.screenshots.config.cjs
 * (cypress.screenshots.config.cjs setzt viewport 1840x1300 + --window-size via
 *  before:browser:launch; specPattern zeigt auf genau diese Datei.)
 */

import { NC_URL, APP_URL, login, createShare, deleteShare } from './helpers'

const FOLDER = '/Portraits'

// Striking Portrait für den Loupe-Shot (5★ rot).
const LOUPE_FILE = 'CAM_0522'

// Locale-fester Share-Button: erster Action-Button in der Desktop-Actions-Leiste
// (allowShare rendert ihn immer zuerst). Vermeidet Text-Matching ('Teilen'/'Share').
const SHARE_BTN = '.sr-filterbar__actions--desktop .sr-filterbar__action'

function openPortraits() {
  cy.visit(`${APP_URL}/#/folder${FOLDER}`)
  cy.get('.sr-grid', { timeout: 15000 }).should('be.visible')
  cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 20000 })
    .should('have.length.greaterThan', 5)
  // Previews wirklich gerendert abwarten (kein Skeleton, echte Bilddaten)
  cy.wait(3000)
}

describe('StarRate Screenshots', () => {
  let token

  before(() => {
    // Zwei saubere Shares anlegen, damit die Freigabe-Liste realistisch aussieht.
    // (Alt-Shares werden vorher per occ gewischt — siehe Seeding im Session-Verlauf.)
    login()
    createShare({ nc_path: FOLDER, permissions: 'view', guest_name: 'Kunde Weber' })
    createShare({ nc_path: FOLDER, permissions: 'rate', guest_name: 'Anna (Model)' })
      .then(share => { token = share.token })
  })

  after(() => deleteShare(token))

  beforeEach(() => {
    login()
    // Fenster-/Screenshot-Größe kommt aus --config viewportWidth/Height (Store-Größe),
    // NICHT aus cy.viewport() — sonst rendert das DOM größer als das Capture-Fenster
    // und der Screenshot ist links-beschnitten.
  })

  it('grid', () => {
    openPortraits()
    cy.screenshot('starrate-grid', { capture: 'viewport', overwrite: true })
  })

  it('loupe', () => {
    openPortraits()
    cy.contains('.sr-grid__item', LOUPE_FILE, { timeout: 10000 }).dblclick()
    cy.get('.sr-loupe', { timeout: 8000 }).should('be.visible')
    cy.wait(2500)
    cy.screenshot('starrate-loupe', { capture: 'viewport', overwrite: true })
    cy.get('body').type('{esc}')
  })

  it('share', () => {
    openPortraits()
    cy.get(SHARE_BTN).first().click()
    cy.get('.sr-share-list', { timeout: 8000 }).should('be.visible')
    cy.wait(1200)
    cy.screenshot('starrate-share', { capture: 'viewport', overwrite: true })
  })

  it('newshare', () => {
    openPortraits()
    cy.get(SHARE_BTN).first().click()
    cy.get('.sr-share-list', { timeout: 8000 }).should('be.visible')
    cy.get('.sr-share-list__new-btn').click()
    cy.get('.sr-share-modal', { timeout: 8000 }).should('be.visible')
    cy.wait(1000)
    cy.screenshot('starrate-newshare', { capture: 'viewport', overwrite: true })
  })

  it('guest', () => {
    cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
    cy.get('.sr-grid, .sr-guest', { timeout: 15000 }).should('be.visible')
    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 20000 })
      .should('have.length.greaterThan', 5)
    cy.wait(3000)
    cy.screenshot('starrate-guest', { capture: 'viewport', overwrite: true })
  })

  // Mobile-Ansichten: Portrait 620x1150 — knapp unter dem 640px-Breakpoint (also
  // echtes Mobile-Layout: 2 Spalten + kompakte Filterleiste), aber breit/hoch genug,
  // dass die Filterleiste komplett sichtbar ist und unten nichts umbricht/abschneidet.
  it('mobile-grid', () => {
    cy.viewport(620, 1150)
    openPortraits()
    cy.screenshot('starrate-mobile-grid', { capture: 'viewport', overwrite: true })
  })

  it('mobile-loupe', () => {
    cy.viewport(620, 1150)
    openPortraits()
    cy.contains('.sr-grid__item', LOUPE_FILE, { timeout: 10000 }).dblclick()
    cy.get('.sr-loupe', { timeout: 8000 }).should('be.visible')
    cy.wait(2500)
    cy.screenshot('starrate-mobile-loupe', { capture: 'viewport', overwrite: true })
    cy.get('body').type('{esc}')
  })

  // Mobile Gast-Ansicht: „Kunde bewertet am Handy". Weniger Controls als Owner →
  // untere Leiste sitzt sauberer. Grid + Loupe (Bewertungs-Moment).
  it('mobile-guest', () => {
    cy.viewport(620, 1150)
    cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 20000 })
      .should('have.length.greaterThan', 5)
    cy.wait(3000)
    cy.screenshot('starrate-mobile-guest', { capture: 'viewport', overwrite: true })
  })

  it('mobile-guest-loupe', () => {
    cy.viewport(620, 1150)
    cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${token}`)
    cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 20000 })
      .should('have.length.greaterThan', 5)
    cy.wait(2000)
    cy.contains('.sr-grid__item', LOUPE_FILE, { timeout: 10000 }).dblclick()
    cy.get('.sr-loupe', { timeout: 8000 }).should('be.visible')
    cy.wait(2500)
    cy.screenshot('starrate-mobile-guest-loupe', { capture: 'viewport', overwrite: true })
  })
})
