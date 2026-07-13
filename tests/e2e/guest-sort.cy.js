/**
 * StarRate E2E – Guest-Galerie folgt der Sortier-Präferenz des Owners (Issue #90).
 *
 * Verifiziert auf API-Ebene (robuster als DOM-Order-Scraping): Owner-Sort setzen →
 * Share anlegen → öffentlichen Guest-Images-Endpoint abfragen → Reihenfolge prüfen.
 * Monotone Checks statt deep-equal, damit sie unabhängig vom Ordnerinhalt und robust
 * gegen mtime-Ties sind.
 */
import { NC_URL, NC_USER, NC_PASS, createShare, deleteShare } from './helpers'

describe('Guest Gallery – Sortierung (Issue #90)', () => {
  let token

  const setOwnerSort = (sort, order) => cy.request({
    method: 'POST',
    url: `${NC_URL}/index.php/apps/starrate/api/settings`,
    body: { default_sort: sort, default_sort_order: order },
    headers: { 'Content-Type': 'application/json', 'OCS-APIREQUEST': 'true' },
    auth: { user: NC_USER, pass: NC_PASS },
  })

  const guestImages = (tok) => cy.request({
    url: `${NC_URL}/index.php/apps/starrate/api/guest/${tok}/images`,
  }).then(resp => resp.body.images)

  afterEach(() => {
    deleteShare(token)
    token = undefined
  })

  after(() => {
    // Owner-Sort auf Default zurücksetzen, damit andere Specs nicht beeinflusst werden
    setOwnerSort('name', 'asc')
  })

  it('name/asc → Bilder alphabetisch aufsteigend', () => {
    setOwnerSort('name', 'asc')
    createShare({ permissions: 'view', guest_name: 'Sort name-asc' }).then(s => {
      token = s.token
      guestImages(token).then(imgs => {
        expect(imgs.length, 'Testordner hat mehrere Bilder').to.be.greaterThan(1)
        for (let i = 1; i < imgs.length; i++) {
          expect(
            imgs[i - 1].name.toLowerCase() <= imgs[i].name.toLowerCase(),
            `${imgs[i - 1].name} <= ${imgs[i].name}`,
          ).to.be.true
        }
      })
    })
  })

  it('name/desc → Bilder alphabetisch absteigend', () => {
    setOwnerSort('name', 'desc')
    createShare({ permissions: 'view', guest_name: 'Sort name-desc' }).then(s => {
      token = s.token
      guestImages(token).then(imgs => {
        expect(imgs.length).to.be.greaterThan(1)
        for (let i = 1; i < imgs.length; i++) {
          expect(
            imgs[i - 1].name.toLowerCase() >= imgs[i].name.toLowerCase(),
            `${imgs[i - 1].name} >= ${imgs[i].name}`,
          ).to.be.true
        }
      })
    })
  })

  it('mtime/desc → Bilder nach Änderungsdatum absteigend', () => {
    setOwnerSort('mtime', 'desc')
    createShare({ permissions: 'view', guest_name: 'Sort mtime-desc' }).then(s => {
      token = s.token
      guestImages(token).then(imgs => {
        expect(imgs.length).to.be.greaterThan(1)
        for (let i = 1; i < imgs.length; i++) {
          expect(imgs[i - 1].mtime, 'mtime absteigend').to.be.at.least(imgs[i].mtime)
        }
      })
    })
  })
})
