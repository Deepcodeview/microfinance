import type { ReactNode } from 'react'

function Icon({ children, size = 18 }: { children: ReactNode; size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {children}
    </svg>
  )
}

export const Icons = {
  home: () => <Icon><path d="M3 10.5 12 3l9 7.5" /><path d="M5 9.5V21h14V9.5" /></Icon>,
  users: () => <Icon><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5" /><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c1.8.8 3 2.5 3.5 5.2" /></Icon>,
  loan: () => <Icon><rect x="3" y="6" width="18" height="13" rx="2" /><path d="M3 10h18M7 15h3" /></Icon>,
  payment: () => <Icon><path d="M12 3v18M16.5 7.5c-.6-1.4-2.3-2.3-4.5-2.3-2.6 0-4.3 1.3-4.3 3.2 0 4.5 9 2.4 9 7 0 2-1.9 3.4-4.7 3.4-2.4 0-4.2-1-4.8-2.6" /></Icon>,
  route: () => <Icon><circle cx="6" cy="19" r="2.5" /><circle cx="18" cy="5" r="2.5" /><path d="M8.5 19H15a3.5 3.5 0 0 0 0-7H9a3.5 3.5 0 0 1 0-7h6.5" /></Icon>,
  cash: () => <Icon><rect x="2.5" y="6" width="19" height="12" rx="2" /><circle cx="12" cy="12" r="2.8" /><path d="M6 9.5v5M18 9.5v5" /></Icon>,
  piggy: () => <Icon><path d="M4 11c0-3.3 3.6-6 8-6 1.4 0 2.7.3 3.9.8L19 4v3.6c1 .9 1.7 2 1.9 3.4H22v3h-1.4a6.6 6.6 0 0 1-2.6 3.3V20h-3v-1.4a10 10 0 0 1-6 0V20H6v-2.5C4.8 16.2 4 14.4 4 11Z" /><circle cx="15.5" cy="10" r=".6" fill="currentColor" /></Icon>,
  payout: () => <Icon><path d="M12 3v12M7 10l5 5 5-5" /><path d="M4 17v3h16v-3" /></Icon>,
  chart: () => <Icon><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" /></Icon>,
  sliders: () => <Icon><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0" /><circle cx="16" cy="6" r="2" /><circle cx="10" cy="12" r="2" /><circle cx="18" cy="18" r="2" /></Icon>,
  shield: () => <Icon><path d="M12 3 4.5 6v6c0 4.4 3.2 8 7.5 9 4.3-1 7.5-4.6 7.5-9V6L12 3Z" /><path d="m9 12 2 2 4-4" /></Icon>,
  history: () => <Icon><path d="M3.5 12a8.5 8.5 0 1 0 2.5-6L3.5 8.5" /><path d="M3.5 4v4.5H8M12 7.5V12l3 2" /></Icon>,
  settings: () => <Icon><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z" /></Icon>,
  plus: () => <Icon size={16}><path d="M12 5v14M5 12h14" /></Icon>,
  panel: () => <Icon><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M9 4v16" /></Icon>,
  menu: () => <Icon><path d="M4 7h16M4 12h16M4 17h16" /></Icon>,
  close: () => <Icon><path d="M6 6l12 12M18 6 6 18" /></Icon>,
  sun: () => <Icon><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></Icon>,
  moon: () => <Icon><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5Z" /></Icon>,
  key: () => <Icon><circle cx="8" cy="15" r="4" /><path d="m11 12 9-9M17 6l3 3" /></Icon>,
  logout: () => <Icon><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10" /></Icon>,
  arrowLeft: () => <Icon size={16}><path d="M19 12H5M11 6l-6 6 6 6" /></Icon>,
  print: () => <Icon size={16}><path d="M7 9V3h10v6M7 17H4v-7h16v7h-3" /><rect x="7" y="14" width="10" height="7" /></Icon>,
  download: () => <Icon size={16}><path d="M12 4v11M7 10l5 5 5-5M4 20h16" /></Icon>,
  check: () => <Icon size={16}><path d="m5 12 5 5 9-10" /></Icon>,
  bell: () => <Icon><path d="M6 9a6 6 0 1 1 12 0c0 5 2 6.5 2 6.5H4S6 14 6 9Z" /><path d="M10 19a2 2 0 0 0 4 0" /></Icon>,
  chat: () => <Icon><path d="M4 5h16v11H9l-5 4V5Z" /></Icon>,
  file: () => <Icon><path d="M14 3H6v18h12V7l-4-4Z" /><path d="M14 3v4h4M9 13h6M9 17h6" /></Icon>,
  trend: () => <Icon><path d="m3 17 6-6 4 4 8-8" /><path d="M15 7h6v6" /></Icon>,
  alert: () => <Icon><path d="M12 3 2 20h20L12 3Z" /><path d="M12 10v4M12 17h.01" /></Icon>,
  pin: () => <Icon><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21Z" /><circle cx="12" cy="9.5" r="2.5" /></Icon>,
  calendar: () => <Icon><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></Icon>,
  wallet: () => <Icon><path d="M4 7h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a1 1 0 0 1-1-1V7Z" /><path d="M4 7V6a2 2 0 0 1 2-2h10v3M16 13.5h.01" /></Icon>,
}
