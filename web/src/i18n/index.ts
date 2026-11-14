/**
 * English / हिन्दी for the web app and both Android apps.
 *
 * Screens are written in English. A small DOM layer translates rendered text, placeholders, tooltips
 * and aria-labels through one dictionary (hi.ts), so every screen switches instantly without a reload
 * and nothing has to be threaded through 40+ components. It only changes text-node values (never
 * replaces nodes), which is safe with React. Anything not in the dictionary stays in English; user data
 * inside `translate="no"`, `.mono`, inputs and textareas is never touched.
 *
 * For text built in code (alerts, document title) use t().
 */
import { HI, HI_PATTERNS } from './hi'

export type Lang = 'en' | 'hi'
const KEY = 'finserve.lang'
const ATTRS = ['placeholder', 'aria-label', 'title'] as const
const SKIP = 'input, textarea, script, style, code, pre, .mono, [translate="no"]'

let lang: Lang = readLang()
const listeners = new Set<(l: Lang) => void>()

function readLang(): Lang {
  try {
    return localStorage.getItem(KEY) === 'hi' ? 'hi' : 'en'
  } catch {
    return 'en'
  }
}

export const getLang = () => lang
export const locale = () => (lang === 'hi' ? 'hi-IN' : 'en-IN')

export function onLangChange(fn: (l: Lang) => void): () => void {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

/** Translate one English string (exact phrase, then patterns); falls back to the input. */
export function t(text: string): string {
  if (lang === 'en') return text
  const trimmed = text.trim()
  if (!trimmed) return text
  const direct = HI[trimmed]
  let out: string | undefined = direct
  if (out === undefined) {
    for (const [re, rep] of HI_PATTERNS) {
      if (re.test(trimmed)) {
        out = trimmed.replace(re, rep as string)
        break
      }
    }
  }
  if (out === undefined) return text
  const lead = text.match(/^\s*/)?.[0] ?? ''
  const tail = text.match(/\s*$/)?.[0] ?? ''
  return lead + out + tail
}

// ---- DOM layer -------------------------------------------------------------------------------

/** text node -> { source: English as React rendered it, shown: what we wrote } */
const tracked = new Map<Text, { source: string; shown: string }>()
let observer: MutationObserver | null = null

const skipped = (el: Element | null) => !!el?.closest(SKIP)

function translateText(node: Text) {
  const value = node.nodeValue ?? ''
  const entry = tracked.get(node)
  if (entry && value === entry.shown) return // our own write
  if (!value.trim() || skipped(node.parentElement)) return
  const source = value
  const shown = t(source)
  tracked.set(node, { source, shown })
  if (shown !== value) node.nodeValue = shown
}

function translateAttrs(el: Element) {
  if (el.closest('[translate="no"]')) return
  for (const attr of ATTRS) {
    if (!el.hasAttribute(attr)) continue
    const store = `data-i18n-${attr}`
    const current = el.getAttribute(attr) ?? ''
    const original = el.getAttribute(store)
    const lastShown = el.getAttribute(`${store}-shown`)
    const source = original !== null && current === lastShown ? original : current
    const shown = t(source)
    el.setAttribute(store, source)
    el.setAttribute(`${store}-shown`, shown)
    if (current !== shown) el.setAttribute(attr, shown)
  }
}

function walk(root: Node) {
  if (root.nodeType === Node.TEXT_NODE) {
    translateText(root as Text)
    return
  }
  if (!(root instanceof Element) && !(root instanceof DocumentFragment)) return
  if (root instanceof Element) translateAttrs(root)
  const texts = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
  for (let n = texts.nextNode(); n; n = texts.nextNode()) translateText(n as Text)
  if (root instanceof Element || root instanceof DocumentFragment) {
    root.querySelectorAll(ATTRS.map((a) => `[${a}]`).join(',')).forEach(translateAttrs)
  }
}

function restoreEnglish() {
  observer?.disconnect()
  tracked.forEach(({ source }, node) => {
    if (node.isConnected && node.nodeValue !== source) node.nodeValue = source
  })
  tracked.clear()
  document.querySelectorAll(ATTRS.map((a) => `[data-i18n-${a}]`).join(',')).forEach((el) => {
    for (const attr of ATTRS) {
      const original = el.getAttribute(`data-i18n-${attr}`)
      if (original !== null) {
        el.setAttribute(attr, original)
        el.removeAttribute(`data-i18n-${attr}`)
        el.removeAttribute(`data-i18n-${attr}-shown`)
      }
    }
  })
}

function startObserver() {
  observer?.disconnect()
  observer = new MutationObserver((mutations) => {
    for (const m of mutations) {
      if (m.type === 'characterData') translateText(m.target as Text)
      else if (m.type === 'attributes') translateAttrs(m.target as Element)
      else m.addedNodes.forEach(walk)
    }
    // Forget nodes React removed, so the map doesn't grow forever.
    if (tracked.size > 4000) tracked.forEach((_, n) => !n.isConnected && tracked.delete(n))
  })
  observer.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: [...ATTRS] })
  walk(document.body)
}

export function setLang(next: Lang) {
  lang = next
  try {
    localStorage.setItem(KEY, next)
  } catch {
    // Applies for this session.
  }
  document.documentElement.lang = next
  if (next === 'hi') startObserver()
  else restoreEnglish()
  listeners.forEach((fn) => fn(next))
}

/** Call once at start-up. */
export function initI18n() {
  document.documentElement.lang = lang
  if (lang === 'hi') {
    if (document.body) startObserver()
    else window.addEventListener('DOMContentLoaded', startObserver, { once: true })
  }
}
