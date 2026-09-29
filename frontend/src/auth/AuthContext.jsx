import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import * as api from '../api'
import { setUnauthorizedHandler, tokenStore } from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  // status: loading (checking a saved token) | guest | authenticated
  const [state, setState] = useState(() => ({
    status: tokenStore.get() ? 'loading' : 'guest',
    user: null,
    permissions: [],
  }))

  const signOutLocally = useCallback(() => {
    tokenStore.set(null)
    setState({ status: 'guest', user: null, permissions: [] })
  }, [])

  useEffect(() => {
    setUnauthorizedHandler(signOutLocally)
  }, [signOutLocally])

  // Restore the session from a saved token on first load.
  useEffect(() => {
    if (state.status !== 'loading') return
    api
      .getMe()
      .then(({ user, permissions }) => setState({ status: 'authenticated', user, permissions }))
      .catch(signOutLocally)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const login = useCallback(async (email, password) => {
    const { token, user, permissions } = await api.login(email, password)
    tokenStore.set(token)
    setState({ status: 'authenticated', user, permissions })
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.logout()
    } catch {
      /* token may already be invalid; sign out locally regardless */
    }
    signOutLocally()
  }, [signOutLocally])

  const value = useMemo(
    () => ({
      ...state,
      login,
      logout,
      can: (permission) => state.permissions.includes(permission),
      isAdmin: state.permissions.includes('settings.manage'),
    }),
    [state, login, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}
