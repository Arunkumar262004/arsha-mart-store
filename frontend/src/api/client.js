import axios from 'axios'

const TOKEN_KEY = 'store_billing_token'

// The API lives on another origin (Laravel on :8000), so the browser sends a
// CORS preflight first; backend/config/cors.php allows this app's origin and
// the Authorization header.
const client = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: {
    // Makes Laravel answer validation failures with JSON 422, not a redirect.
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  timeout: 30000,
})

export const tokenStore = {
  get: () => {
    try {
      return localStorage.getItem(TOKEN_KEY)
    } catch {
      return null
    }
  },
  set: (token) => {
    try {
      if (token) localStorage.setItem(TOKEN_KEY, token)
      else localStorage.removeItem(TOKEN_KEY)
    } catch {
      /* storage unavailable: session lasts until reload */
    }
  },
}

client.interceptors.request.use((config) => {
  const token = tokenStore.get()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

let onUnauthorized = () => {}
/** Registered by AuthProvider: called when the API says the token is no longer valid. */
export const setUnauthorizedHandler = (handler) => {
  onUnauthorized = handler
}

client.interceptors.response.use(
  (response) => response,
  (error) => {
    const isLogin = error.config?.url?.endsWith('/login')
    if (error.response?.status === 401 && !isLogin) onUnauthorized()
    return Promise.reject(error)
  },
)

/**
 * Normalises an axios error into { message, errors, status } where errors is
 * Laravel's field => [messages] map (empty when not a validation error).
 */
export function parseApiError(error) {
  const data = error?.response?.data
  if (data) {
    return {
      message: data.message ?? 'Request failed.',
      errors: data.errors ?? {},
      status: error.response.status,
      conflict: data.conflict ?? null,
    }
  }
  if (error?.request) {
    const message = import.meta.env.DEV
      ? 'Cannot reach the API. Is `php artisan serve` running on port 8000?'
      : 'Cannot reach the server. Please check your connection and try again.'
    return { message, errors: {}, status: 0 }
  }
  return { message: error?.message ?? 'Unexpected error.', errors: {}, status: 0 }
}

export default client
