import { getLang, setLang, type Lang } from '../i18n'

const OPTIONS: [Lang, string][] = [
  ['en', 'English'],
  ['hi', 'हिन्दी'],
]

/** English / हिन्दी toggle. Labels are in their own script, so they are never translated. */
export function LanguageSwitch({ block = false }: { block?: boolean }) {
  const lang = getLang()
  return (
    <div className="segmented" translate="no" role="group" aria-label="Language / भाषा" style={block ? { width: '100%' } : undefined}>
      {OPTIONS.map(([value, label]) => (
        <button key={value} type="button" aria-pressed={lang === value} onClick={() => lang !== value && setLang(value)} style={block ? { flex: 1 } : undefined}>
          {label}
        </button>
      ))}
    </div>
  )
}
