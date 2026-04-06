/**
 * StarRate E2E – API Security & Edge Cases
 *
 * Testet: Auth-Prüfung, ungültige Werte, XSS-Versuch, Boundary-Values.
 */

import { NC_URL, NC_USER, NC_PASS, login, createShare, deleteShare } from './helpers'

describe('API Security', () => {

  // ── Auth-Prüfung ───────────────────────────────────────────────────────

  describe('Authentifizierung', () => {
    it('API ohne Auth → 401 oder Redirect', () => {
      cy.request({
        url: `${NC_URL}/index.php/apps/starrate/api/images?path=/`,
        failOnStatusCode: false,
      }).then(resp => {
        // NC redirected zur Login-Seite (302/303) oder gibt 401
        expect([200, 302, 303, 401]).to.include(resp.status)
        // Wenn 200, ist es die Login-Seite (HTML)
        if (resp.status === 200) {
          expect(resp.headers['content-type']).to.include('text/html')
        }
      })
    })

    it('Share-API ohne Auth → 401 oder Redirect', () => {
      cy.request({
        url: `${NC_URL}/index.php/apps/starrate/api/share`,
        failOnStatusCode: false,
      }).then(resp => {
        expect([302, 303, 401]).to.include(resp.status)
      })
    })

    it('Share-API mit falschen Credentials → 401', () => {
      cy.request({
        url: `${NC_URL}/index.php/apps/starrate/api/share`,
        auth: { user: 'nobody', pass: 'wrong' },
        failOnStatusCode: false,
      }).then(resp => {
        expect([401, 403]).to.include(resp.status)
      })
    })
  })

  // ── Rating Boundary Values ──────────────────────────────────────────────

  describe('Rating Boundaries', () => {
    let token

    before(() => {
      login()
      createShare({ permissions: 'rate', guest_name: 'Boundary', allow_pick: true }).then(share => {
        token = share.token
      })
    })

    after(() => deleteShare(token))

    it('Rating > 5 → 422', () => {
      cy.request({
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/images`,
      }).then(resp => {
        const fileId = resp.body.images[0].id
        cy.request({
          method: 'POST',
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
          body: { file_id: fileId, rating: 99, guest_name: 'Test' },
          headers: { 'Content-Type': 'application/json' },
          failOnStatusCode: false,
        }).then(r => {
          expect(r.status).to.eq(422)
        })
      })
    })

    it('Rating < 0 → 422', () => {
      cy.request({
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/images`,
      }).then(resp => {
        const fileId = resp.body.images[0].id
        cy.request({
          method: 'POST',
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
          body: { file_id: fileId, rating: -1, guest_name: 'Test' },
          headers: { 'Content-Type': 'application/json' },
          failOnStatusCode: false,
        }).then(r => {
          expect(r.status).to.eq(422)
        })
      })
    })

    it('Ungültiger Pick-Status → 422', () => {
      cy.request({
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/images`,
      }).then(resp => {
        const fileId = resp.body.images[0].id
        cy.request({
          method: 'POST',
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
          body: { file_id: fileId, pick: 'INVALID', guest_name: 'Test' },
          headers: { 'Content-Type': 'application/json' },
          failOnStatusCode: false,
        }).then(r => {
          expect(r.status).to.eq(422)
        })
      })
    })

    it('Fehlende file_id → 400', () => {
      cy.request({
        method: 'POST',
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
        body: { rating: 3, guest_name: 'Test' },
        headers: { 'Content-Type': 'application/json' },
        failOnStatusCode: false,
      }).then(r => {
        expect(r.status).to.eq(400)
      })
    })

    it('Rating 0 (Bewertung entfernen) → OK', () => {
      cy.request({
        url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/images`,
      }).then(resp => {
        const fileId = resp.body.images[0].id
        cy.request({
          method: 'POST',
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${token}/rate`,
          body: { file_id: fileId, rating: 0, guest_name: 'Test' },
          headers: { 'Content-Type': 'application/json' },
        }).then(r => {
          expect(r.status).to.eq(200)
        })
      })
    })
  })

  // ── XSS / Injection ────────────────────────────────────────────────────

  describe('XSS & Injection', () => {

    it('Share mit XSS im Guest-Name → wird escaped', () => {
      login()
      createShare({
        permissions: 'rate',
        guest_name: '<script>alert("xss")</script>',
      }).then(share => {
        cy.clearCookies()
        cy.visit(`${NC_URL}/index.php/apps/starrate/guest/${share.token}`)
        cy.get('.sr-grid', { timeout: 15000 }).should('be.visible')

        // Script darf nicht ausgeführt werden
        // Guest-Label zeigt den Text escaped an
        cy.get('.sr-breadcrumb__guest-label').should('contain', '<script>')
        cy.get('.sr-breadcrumb__guest-label').should('not.have.html', '<script>')

        deleteShare(share.token)
      })
    })

    it('Rating mit XSS im guest_name → kein Fehler', () => {
      login()
      createShare({ permissions: 'rate', guest_name: 'Normal' }).then(share => {
        cy.request({
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${share.token}/images`,
        }).then(resp => {
          const fileId = resp.body.images[0].id
          cy.request({
            method: 'POST',
            url: `${NC_URL}/index.php/apps/starrate/api/guest/${share.token}/rate`,
            body: {
              file_id: fileId,
              rating: 3,
              guest_name: '"><img src=x onerror=alert(1)>',
            },
            headers: { 'Content-Type': 'application/json' },
          }).then(r => {
            expect(r.status).to.eq(200)
          })
        })

        deleteShare(share.token)
      })
    })
  })

  // ── Share-Verwaltung via API ────────────────────────────────────────────

  describe('Share CRUD', () => {
    it('Create → List → Update → Delete Lifecycle', () => {
      login()

      // Create
      cy.request({
        method: 'POST',
        url: `${NC_URL}/index.php/apps/starrate/api/share`,
        body: { nc_path: '/Fotos/E2E-Test', permissions: 'rate', guest_name: 'CRUD-Test' },
        headers: { 'Content-Type': 'application/json', 'OCS-APIREQUEST': 'true' },
        auth: { user: NC_USER, pass: NC_PASS },
      }).then(resp => {
        expect(resp.status).to.eq(201)
        const token = resp.body.share.token
        expect(token).to.have.length.greaterThan(10)

        // List
        cy.request({
          url: `${NC_URL}/index.php/apps/starrate/api/share`,
          headers: { 'OCS-APIREQUEST': 'true' },
          auth: { user: NC_USER, pass: NC_PASS },
        }).then(listResp => {
          const found = listResp.body.shares.find(s => s.token === token)
          expect(found).to.exist
          expect(found.guest_name).to.eq('CRUD-Test')
        })

        // Update: deaktivieren + allow_pick
        cy.request({
          method: 'PUT',
          url: `${NC_URL}/index.php/apps/starrate/api/share/${token}`,
          body: { active: false, allow_pick: true },
          headers: { 'Content-Type': 'application/json', 'OCS-APIREQUEST': 'true' },
          auth: { user: NC_USER, pass: NC_PASS },
        }).then(updateResp => {
          expect(updateResp.body.share.active).to.be.false
          expect(updateResp.body.share.allow_pick).to.be.true
        })

        // Delete
        cy.request({
          method: 'DELETE',
          url: `${NC_URL}/index.php/apps/starrate/api/share/${token}`,
          headers: { 'OCS-APIREQUEST': 'true' },
          auth: { user: NC_USER, pass: NC_PASS },
        }).then(delResp => {
          expect(delResp.body.ok).to.be.true
        })

        // Verify deleted
        cy.request({
          url: `${NC_URL}/index.php/apps/starrate/api/share`,
          headers: { 'OCS-APIREQUEST': 'true' },
          auth: { user: NC_USER, pass: NC_PASS },
        }).then(listResp => {
          const found = listResp.body.shares.find(s => s.token === token)
          expect(found).to.not.exist
        })
      })
    })

    it('Share für nicht-existierenden Ordner → Images leer oder Fehler', () => {
      login()
      createShare({ nc_path: '/NICHT/EXISTENT/ORDNER', permissions: 'view', guest_name: 'Ghost' }).then(share => {
        cy.request({
          url: `${NC_URL}/index.php/apps/starrate/api/guest/${share.token}/images`,
          failOnStatusCode: false,
        }).then(resp => {
          // Server-Fehler oder leeres Ergebnis
          if (resp.status === 200) {
            expect(resp.body.images).to.have.length(0)
          } else {
            expect(resp.status).to.be.greaterThan(399)
          }
        })

        deleteShare(share.token)
      })
    })
  })
})
