import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import LoupeView from '../../src/components/LoupeView.vue'

const images = [{ id: 1, name: 'a.jpg', rating: 0, color: null, pick: 'none' }]

function factory(props = {}) {
  return mount(LoupeView, {
    props: {
      images,
      initialIndex: 0,
      previewUrlFn: (id) => `/preview/${id}`,
      ...props,
    },
  })
}

describe('LoupeView Download-Button', () => {
  let wrapper

  afterEach(() => wrapper?.unmount())

  it('zeigt den Download-Button wenn canDownload=true', () => {
    wrapper = factory({ canDownload: true })
    expect(wrapper.find('.sr-loupe__download').exists()).toBe(true)
  })

  it('versteckt den Download-Button wenn canDownload=false (Default)', () => {
    wrapper = factory()
    expect(wrapper.find('.sr-loupe__download').exists()).toBe(false)
  })

  it('emittiert "download" mit dem aktuellen Bild beim Klick', async () => {
    wrapper = factory({ canDownload: true })
    await wrapper.find('.sr-loupe__download').trigger('click')
    expect(wrapper.emitted('download')).toBeTruthy()
    expect(wrapper.emitted('download')[0][0]).toMatchObject({ id: 1, name: 'a.jpg' })
  })
})
