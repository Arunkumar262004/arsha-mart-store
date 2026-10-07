import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import * as api from '../api'
import { setUnauthorizedHandler, storeSelection, tokenStore } from '../api/client'

const AuthContext = createContext(null)

const GUEST = { status: 'guest', user: null, permissions: [], stores: [], allStores: false, selection: null }

/**
 * Picks the store to work in from the saved choice: users tied to one store
 * always get it; others keep a saved store that still exists (or "all" when
 * allowed), else their own store, else the first store. Returns a store id
 * as a string, 'all' or null (no stores at all).
 */
function resolveSelection(saved, user, stores, allStores) {
  if (user?.store_id) return String(user.store_id)
  if (saved === 'all' && allStores) return 'all'
  if (saved && stores.some((s) => String(s.id) === saved)) return saved
  return stores[0] ? String(stores[0].id) : null
}

/** Session state from a login or /me response; also persists the store choice. */
function sessionFrom({ user, permissions, stores = [], all_stores: allStores = false }) {
  const selection = resolveSelection(storeSelection.get(), user, stores, allStores)
  // Saved before the state update so the very next request carries it.
  storeSelection.set(selection)
  return { status: 'authenticated', user, permissions, stores, allStores, selection }
}

export function AuthProvider({ children }) {
  // status: loading (checking a saved token) | guest | authenticated
  const [state, setState] = useState(() => ({ ...GUEST, status: tokenStore.get() ? 'loading' : 'guest' }))

  const signOutLocally = useCallback(() => {
    tokenStore.set(null)
    setState(GUEST)
  }, [])

  useEffect(() => {
    setUnauthorizedHandler(signOutLocally)
  }, [signOutLocally])

  // Restore the session from a saved token on first load.
  useEffect(() => {
    if (state.status !== 'loading') return
    api
      .getMe()
      .then((profile) => setState(sessionFrom(profile)))
      .catch(signOutLocally)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const login = useCallback(async (email, password) => {
    const { token, ...profile } = await api.login(email, password)
    tokenStore.set(token)
    setState(sessionFrom(profile))
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.logout()
    } catch {
      /* token may already be invalid; sign out locally regardless */
    }
    signOutLocally()
  }, [signOutLocally])

  /** Replace the signed-in user's details, e.g. after uploading a photo. */
  const updateUser = useCallback((user) => setState((s) => ({ ...s, user })), [])

  /** Switch to a store id, or 'all' for every store (ignored when not allowed). */
  const setCurrentStore = useCallback((idOrAll) => {
    setState((s) => {
      const wanted = idOrAll == null ? null : String(idOrAll)
      const selection = resolveSelection(wanted, s.user, s.stores, s.allStores)
      storeSelection.set(selection)
      return selection === s.selection ? s : { ...s, selection }
    })
  }, [])

  /** Reload the switchable stores, e.g. after an admin adds or deactivates one. */
  const refreshStores = useCallback(async () => {
    const profile = await api.getMe()
    setState((s) => (s.status === 'authenticated' ? sessionFrom(profile) : s))
  }, [])

  const value = useMemo(() => {
    const { selection, stores, allStores, user } = state
    const isAllStores = selection === 'all'
    const currentStore = isAllStores ? null : (stores.find((s) => String(s.id) === selection) ?? user?.store ?? null)
    return {
      ...state,
      login,
      logout,
      updateUser,
      can: (permission) => state.permissions.includes(permission),
      isAdmin: state.permissions.includes('settings.manage'),
      stores,
      currentStore,
      isAllStores,
      // Users tied to one store cannot switch; others may when there is a choice.
      canSwitchStores: !user?.store_id && (stores.length > 1 || (allStores && stores.length > 0)),
      canSelectAllStores: !user?.store_id && allStores,
      setCurrentStore,
      refreshStores,
    }
  }, [state, login, logout, updateUser, setCurrentStore, refreshStores])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}
