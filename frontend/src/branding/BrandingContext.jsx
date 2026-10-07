import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { getBranding } from '../api'
import defaultLogo from '../assets/inofex-logo.png'
import defaultMark from '../assets/inofex-mark.png'

const BrandingContext = createContext(null)

const DEFAULTS = { company_name: 'Inofex Retail', tagline: 'Retail billing & accounts', logo: null, favicon: null }

/**
 * The business name, logo and favicon an admin sets under Settings → Company.
 * Until something is uploaded the built-in Inofex artwork is used.
 *
 * - name / tagline: shown in the sidebar, login page and browser tab
 * - mark: small square logo (sidebar, login)
 * - logo: full logo for printed documents
 */
export function BrandingProvider({ children }) {
  const [branding, setBranding] = useState(DEFAULTS)

  const refresh = useCallback(
    () =>
      getBranding()
        .then(setBranding)
        .catch(() => {}),
    [],
  )

  useEffect(() => {
    refresh()
  }, [refresh])

  // Browser tab: title and icon follow the settings.
  useEffect(() => {
    document.title = branding.company_name
    const link = document.querySelector('link[rel="icon"]') ?? document.head.appendChild(document.createElement('link'))
    link.rel = 'icon'
    link.href = branding.favicon ?? branding.logo ?? '/favicon.png'
  }, [branding])

  const value = useMemo(
    () => ({
      name: branding.company_name,
      tagline: branding.tagline,
      logo: branding.logo ?? defaultLogo,
      mark: branding.logo ?? defaultMark,
      uploadedLogo: branding.logo,
      uploadedFavicon: branding.favicon,
      // Legal details: legal_name, gstin, pan, phone, email, website, address, city, state, state_code, pincode
      details: branding,
      setBranding,
      refresh,
    }),
    [branding, refresh],
  )

  return <BrandingContext.Provider value={value}>{children}</BrandingContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export const useBranding = () => useContext(BrandingContext)
