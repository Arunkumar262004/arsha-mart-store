import { useEffect, useRef } from 'react'

const CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID
const SCRIPT_SRC = 'https://accounts.google.com/gsi/client'

let scriptPromise = null

/** Loads Google Identity Services once per page. */
function loadGoogleScript() {
  if (window.google?.accounts?.id) return Promise.resolve()
  scriptPromise ??= new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = SCRIPT_SRC
    script.async = true
    script.onload = resolve
    script.onerror = () => {
      scriptPromise = null
      reject(new Error('Could not load Google sign-in.'))
    }
    document.head.appendChild(script)
  })
  return scriptPromise
}

/**
 * Google's own "Sign in with Google" button. Calls onCredential with the
 * Google ID token; renders nothing when VITE_GOOGLE_CLIENT_ID is not set.
 */
export default function GoogleSignInButton({ onCredential, onError }) {
  const container = useRef(null)
  // Latest callbacks without re-rendering Google's button on every render.
  const handlers = useRef({ onCredential, onError })
  useEffect(() => {
    handlers.current = { onCredential, onError }
  })

  useEffect(() => {
    if (!CLIENT_ID) return
    let cancelled = false

    loadGoogleScript()
      .then(() => {
        if (cancelled || !container.current) return
        window.google.accounts.id.initialize({
          client_id: CLIENT_ID,
          callback: ({ credential }) => handlers.current.onCredential(credential),
        })
        window.google.accounts.id.renderButton(container.current, {
          theme: 'outline',
          size: 'large',
          shape: 'pill',
          text: 'signin_with',
          width: container.current.offsetWidth || 320,
        })
      })
      .catch((e) => handlers.current.onError?.(e))

    return () => {
      cancelled = true
    }
  }, [])

  if (!CLIENT_ID) return null

  return <div ref={container} className="flex min-h-10 w-full justify-center" />
}
