import { vi } from 'vitest'

// localStorage mock
const localStorageMock = (() => {
  let store = {}
  return {
    getItem:   (key) => store[key] ?? null,
    setItem:   (key, val) => { store[key] = String(val) },
    removeItem:(key) => { delete store[key] },
    clear:     () => { store = {} },
  }
})()
Object.defineProperty(window, 'localStorage', { value: localStorageMock })

// matchMedia mock
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation(query => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
})

// ResizeObserver mock
global.ResizeObserver = vi.fn().mockImplementation(() => ({
  observe:   vi.fn(),
  unobserve: vi.fn(),
  disconnect:vi.fn(),
}))
