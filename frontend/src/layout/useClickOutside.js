import { useEffect } from 'react'

/** Calls `onOutside` when a pointer-down or Escape happens outside `ref`. */
export default function useClickOutside(ref, onOutside, active = true) {
  useEffect(() => {
    if (!active) return
    const onPointer = (e) => ref.current && !ref.current.contains(e.target) && onOutside()
    const onKey = (e) => e.key === 'Escape' && onOutside()
    document.addEventListener('pointerdown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [ref, onOutside, active])
}
