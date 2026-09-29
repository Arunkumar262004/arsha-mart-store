import { useEffect, useState } from 'react'
import { Maximize, Minimize } from 'lucide-react'
import { headerButtonClass } from './headerButton'

/** Header button that puts the whole app into browser full screen (Esc also leaves it). */
export default function FullscreenToggle() {
  const [isFullscreen, setIsFullscreen] = useState(() => Boolean(document.fullscreenElement))

  useEffect(() => {
    const onChange = () => setIsFullscreen(Boolean(document.fullscreenElement))
    document.addEventListener('fullscreenchange', onChange)
    return () => document.removeEventListener('fullscreenchange', onChange)
  }, [])

  // Some browsers (e.g. iPhone Safari) don't allow it.
  if (!document.fullscreenEnabled) return null

  const toggle = () =>
    (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()).catch(() => {})

  const label = isFullscreen ? 'Exit full screen' : 'Full screen'

  return (
    <button type="button" onClick={toggle} className={headerButtonClass} aria-label={label} title={label}>
      {isFullscreen ? <Minimize size={18} /> : <Maximize size={18} />}
    </button>
  )
}
