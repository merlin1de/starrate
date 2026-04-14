import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import FolderPopover from '../../src/components/FolderPopover.vue'

const FOLDERS = [
  { name: 'Keeper', path: '/Shooting/Keeper' },
  { name: 'Maybe',  path: '/Shooting/Maybe' },
  { name: 'Ausschuss', path: '/Shooting/Ausschuss' },
]

let currentWrapper = null

function factory(folders = FOLDERS) {
  const w = mount(FolderPopover, {
    props: { folders },
    attachTo: document.body,
  })
  currentWrapper = w
  return w
}

afterEach(() => {
  // Sauber unmounten, damit Teleport-Inhalte entfernt werden
  if (currentWrapper) {
    currentWrapper.unmount()
    currentWrapper = null
  }
})

describe('FolderPopover', () => {
  it('Trigger zeigt Anzahl der Ordner', () => {
    const w = factory()
    expect(w.find('.sr-folder-popover__trigger').text()).toContain('3')
  })

  it('Menü ist initial geschlossen', () => {
    factory()
    expect(document.querySelector('.sr-folder-popover__menu')).toBe(null)
  })

  it('Klick auf Trigger öffnet Menü mit allen Ordnern', async () => {
    const w = factory()
    await w.find('.sr-folder-popover__trigger').trigger('click')
    await flushPromises()
    const items = document.querySelectorAll('.sr-folder-popover__item')
    expect(items.length).toBe(3)
    expect(items[0].textContent).toContain('Keeper')
    expect(items[2].textContent).toContain('Ausschuss')
  })

  it('Klick auf Item emittiert navigate + schließt Menü', async () => {
    const w = factory()
    await w.find('.sr-folder-popover__trigger').trigger('click')
    await flushPromises()
    document.querySelectorAll('.sr-folder-popover__item')[1].click()
    await flushPromises()
    expect(w.emitted('navigate')).toBeTruthy()
    expect(w.emitted('navigate')[0]).toEqual(['/Shooting/Maybe'])
    expect(document.querySelector('.sr-folder-popover__menu')).toBe(null)
  })

  it('Klick auf Catcher (Außen-Klick) schließt Menü', async () => {
    const w = factory()
    await w.find('.sr-folder-popover__trigger').trigger('click')
    await flushPromises()
    document.querySelector('.sr-folder-popover__catcher').click()
    await flushPromises()
    expect(document.querySelector('.sr-folder-popover__menu')).toBe(null)
  })

  it('ESC schließt Menü', async () => {
    const w = factory()
    await w.find('.sr-folder-popover__trigger').trigger('click')
    await flushPromises()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()
    expect(document.querySelector('.sr-folder-popover__menu')).toBe(null)
  })

  it('aria-expanded wechselt mit Offen-Zustand', async () => {
    const w = factory()
    const trigger = w.find('.sr-folder-popover__trigger')
    expect(trigger.attributes('aria-expanded')).toBe('false')
    await trigger.trigger('click')
    await flushPromises()
    expect(trigger.attributes('aria-expanded')).toBe('true')
  })

  it('Zweiter Trigger-Klick schließt Menü wieder', async () => {
    const w = factory()
    const trigger = w.find('.sr-folder-popover__trigger')
    await trigger.trigger('click')
    await flushPromises()
    expect(document.querySelector('.sr-folder-popover__menu')).not.toBe(null)
    await trigger.trigger('click')
    await flushPromises()
    expect(document.querySelector('.sr-folder-popover__menu')).toBe(null)
  })
})
