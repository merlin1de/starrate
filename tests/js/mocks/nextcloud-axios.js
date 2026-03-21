import { vi } from 'vitest'

const axios = {
  get:    vi.fn().mockResolvedValue({ data: {} }),
  post:   vi.fn().mockResolvedValue({ data: {} }),
  put:    vi.fn().mockResolvedValue({ data: {} }),
  delete: vi.fn().mockResolvedValue({ data: {} }),
}

export default axios
