import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, appToken, setAuthHandlers } from './api'
import { clearCache } from './offline'
import type { User } from './types'

type LoginResult = { status: 'signed_in' } | { status: 'mfa_required'; challenge: string }

interface AuthState {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<LoginResult>
  verifyMfa: (challenge: string, code: string) => Promise<void>
  /** Customer app self-registration; signs the new customer in. */
  register: (details: Record<string, string>) => Promise<void>
  logout: () => Promise<void>
  /** Re-reads the profile, e.g. after settings that affect navigation change. */
  refresh: () => Promise<void>
  can: (permission: string) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = useCallback(async () => {
    setUser(await api.get<User>('/me'))
  }, [])

  useEffect(() => {
    setAuthHandlers({
      unauthenticated: () => {
        appToken.set(null)
        setUser(null)
      },
      mfaSetupRequired: () => setUser((u) => (u ? { ...u, mfa_setup_required: true } : u)),
    })
    // The session cookie is invisible to scripts, so ask the API whether we're signed in.
    api
      .get<User>('/me')
      .then(setUser)
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (email: string, password: string): Promise<LoginResult> => {
    const result = await api.post<{ user?: User; token?: string; mfa_required?: boolean; challenge?: string }>('/auth/login', { email, password })
    if (result.mfa_required && result.challenge) return { status: 'mfa_required', challenge: result.challenge }
    appToken.set(result.token ?? null)
    setUser(result.user ?? null)
    return { status: 'signed_in' }
  }, [])

  const verifyMfa = useCallback(async (challenge: string, code: string) => {
    const result = await api.post<{ user: User; token?: string }>('/auth/mfa', { challenge, code })
    appToken.set(result.token ?? null)
    setUser(result.user)
  }, [])

  const register = useCallback(async (details: Record<string, string>) => {
    const result = await api.post<{ user: User; token?: string }>('/public/register', details)
    appToken.set(result.token ?? null)
    setUser(result.user)
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      setUser(null)
      appToken.set(null)
      void clearCache()
    }
  }, [])

  const can = useCallback((p: string) => user?.permissions.includes(p) ?? false, [user])

  const value = useMemo<AuthState>(() => ({ user, loading, login, verifyMfa, register, logout, refresh, can }), [user, loading, login, verifyMfa, register, logout, refresh, can])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
