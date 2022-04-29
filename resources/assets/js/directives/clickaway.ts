import { Directive } from 'vue'

let handler: any

/**
 * A fork of https://github.com/simplesmiler/vue-clickaway.
 * Trigger a function if the user clicks out of the bound element.
 */
export const clickaway: Directive = {
  created (el: HTMLElement, { value }: { value: TAnyFunction }): void {
    handler = (e: MouseEvent) => el.contains(e.target as Node) || value()
    document.addEventListener('click', handler, { once: true })
    document.addEventListener('contextmenu', handler, { once: true })
  }
}
