// Cypress Support-File (global für alle E2E-Specs).
//
// Nextclouds Core-JS (core-unsupported-browser-redirect.js → Browser-Support-Check)
// wirft unter Cypress' Electron-User-Agent eine "Cannot read properties of undefined
// (reading 'from')"-Exception. Das ist ein NC-Core-×-Testbrowser-Problem, KEIN
// StarRate-Fehler (die App rendert normal). Cypress failt aber per Default jeden Test
// bei jeder uncaught Exception — deshalb hier gezielt nur diese eine ignorieren.
// Alle anderen App-Fehler failen den Test weiterhin.
Cypress.on('uncaught:exception', (err) => {
  if (err && err.message && err.message.includes("reading 'from'")) {
    return false
  }
  return undefined
})
