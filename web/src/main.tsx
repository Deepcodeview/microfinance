import { StrictMode, useEffect, useState } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { getLang, initI18n, onLangChange } from './i18n'

initI18n()

/** Re-renders the app when the language changes, so dates and code-built text refresh too. */
function Root() {
  const [lang, setLang] = useState(getLang)
  useEffect(() => onLangChange(setLang), [])
  return <App key={lang} />
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Root />
  </StrictMode>,
)

// Installable, offline-capable app shell (production builds only; the dev server must not be cached).
if (import.meta.env.PROD && import.meta.env.VITE_APP_MODE === undefined && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {})
  })
}
