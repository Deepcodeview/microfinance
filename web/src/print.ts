import { IS_APP } from './config'

interface CapacitorBridge {
  nativePromise?: (plugin: string, method: string, options: Record<string, unknown>) => Promise<unknown>
}

/**
 * Print the page on show, or save it as a PDF. Browsers: the normal print dialog (Destination → Save as PDF),
 * with `name` as the suggested file name. Android apps: the WebView ignores window.print(), so the app's
 * Printer plugin opens Android's print screen instead (Save as PDF is one of its printers).
 */
export function printPage(name: string): void {
  const bridge = (window as unknown as { Capacitor?: CapacitorBridge }).Capacitor
  if (IS_APP && bridge?.nativePromise) {
    bridge.nativePromise('Printer', 'print', { name }).catch(() => window.print())
    return
  }
  const title = document.title
  document.title = name
  window.print()
  document.title = title
}
