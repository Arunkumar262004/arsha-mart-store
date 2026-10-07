import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Check, ChevronDown, Search } from 'lucide-react'

/**
 * Searchable product dropdown: type part of a name, code, HSN or category,
 * move with ↑ ↓, pick with Enter or a click.
 *
 * describe(product) → text shown after the name, e.g. "12 left".
 * isDisabled(product) → true for products that cannot be picked (out of stock,
 * already on another line); they stay visible but greyed out.
 *
 * The list is rendered in a portal with fixed positioning so tables and
 * popups with overflow never clip it; it opens upwards near the screen bottom.
 */
export default function ProductPicker({
  products,
  value,
  onChange,
  describe = () => '',
  isDisabled = () => false,
  placeholder = 'Select a product…',
  ariaLabel,
  invalid = false,
}) {
  const listId = useId()
  const triggerRef = useRef(null)
  const panelRef = useRef(null)
  const searchRef = useRef(null)
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const [position, setPosition] = useState(null)

  const selected = products.find((p) => String(p.id) === String(value))

  const matches = useMemo(() => {
    const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean)
    if (words.length === 0) return products
    return products.filter((p) => {
      const haystack = [p.name, p.code, p.hsn_code, p.category].filter(Boolean).join(' ').toLowerCase()
      return words.every((w) => haystack.includes(w))
    })
  }, [products, query])

  const place = () => {
    const rect = triggerRef.current?.getBoundingClientRect()
    if (!rect) return
    const below = window.innerHeight - rect.bottom
    const up = below < 320 && rect.top > below
    setPosition({ left: rect.left, width: rect.width, ...(up ? { bottom: window.innerHeight - rect.top + 4 } : { top: rect.bottom + 4 }) })
  }

  const openPanel = () => {
    place()
    setQuery('')
    const index = selected ? products.indexOf(selected) : products.findIndex((p) => !isDisabled(p))
    setActive(Math.max(0, index))
    setOpen(true)
  }

  const close = (refocus = false) => {
    setOpen(false)
    if (refocus) triggerRef.current?.focus()
  }

  const choose = (product) => {
    if (!product || isDisabled(product)) return
    onChange(String(product.id))
    close(true)
  }

  // Follow the field while the page or a popup scrolls; close on outside clicks.
  useLayoutEffect(() => {
    if (!open) return
    searchRef.current?.focus()
    const onPointer = (e) => {
      if (!panelRef.current?.contains(e.target) && !triggerRef.current?.contains(e.target)) setOpen(false)
    }
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    document.addEventListener('pointerdown', onPointer)
    return () => {
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
      document.removeEventListener('pointerdown', onPointer)
    }
  }, [open])

  // Keep the highlighted row in view.
  useEffect(() => {
    if (open) panelRef.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' })
  }, [active, open])

  const move = (step) => {
    if (matches.length === 0) return
    let next = active
    for (let i = 0; i < matches.length; i++) {
      next = (next + step + matches.length) % matches.length
      if (!isDisabled(matches[next])) break
    }
    setActive(next)
  }

  const onSearchKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1) }
    else if (e.key === 'Enter') { e.preventDefault(); choose(matches[active]) }
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true) }
    else if (e.key === 'Tab') close()
  }

  const onTriggerKey = (e) => {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); openPanel() }
    else if (e.key.length === 1 && /\S/.test(e.key)) {
      // Typing on the closed field starts a search with that letter.
      e.preventDefault()
      openPanel()
      setQuery(e.key)
    }
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-label={ariaLabel}
        onClick={() => (open ? close() : openPanel())}
        onKeyDown={onTriggerKey}
        className={`flex w-full items-center gap-2 rounded-lg border bg-white px-3 py-2 text-left text-sm shadow-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100 ${
          invalid ? 'border-red-400' : 'border-slate-300'
        } ${open ? 'border-brand-500 ring-2 ring-brand-100' : ''}`}
      >
        <span className={`min-w-0 flex-1 truncate ${selected ? 'text-slate-900' : 'text-slate-400'}`}>
          {selected ? (
            <>
              {selected.name}
              {describe(selected) && <span className="text-slate-500"> ({describe(selected)})</span>}
            </>
          ) : (
            placeholder
          )}
        </span>
        <ChevronDown size={16} className={`shrink-0 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
      </button>

      {open && position &&
        createPortal(
          <div
            ref={panelRef}
            style={{ position: 'fixed', left: position.left, width: Math.max(position.width, 280), top: position.top, bottom: position.bottom }}
            className="z-[70] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl"
          >
            <div className="flex items-center gap-2 border-b border-slate-100 px-3 py-2">
              <Search size={16} className="shrink-0 text-slate-400" aria-hidden />
              <input
                ref={searchRef}
                value={query}
                onChange={(e) => { setQuery(e.target.value); setActive(0) }}
                onKeyDown={onSearchKey}
                placeholder="Search by name, code, HSN or category"
                className="w-full bg-transparent py-1 text-sm outline-none placeholder:text-slate-400"
                aria-label="Search products"
                aria-controls={listId}
                aria-activedescendant={matches[active] ? `${listId}-${matches[active].id}` : undefined}
              />
              <span className="shrink-0 text-xs text-slate-400">{matches.length}</span>
            </div>
            <ul id={listId} role="listbox" className="max-h-72 overflow-y-auto py-1">
              {matches.length === 0 && <li className="px-3 py-6 text-center text-sm text-slate-500">No products match “{query}”.</li>}
              {matches.map((p, i) => {
                const disabled = isDisabled(p)
                const isSelected = selected && p.id === selected.id
                return (
                  <li
                    key={p.id}
                    id={`${listId}-${p.id}`}
                    role="option"
                    aria-selected={isSelected}
                    aria-disabled={disabled}
                    data-index={i}
                    onMouseEnter={() => !disabled && setActive(i)}
                    onClick={() => choose(p)}
                    className={`flex cursor-pointer items-center gap-2 px-3 py-2 text-sm ${
                      disabled ? 'cursor-not-allowed text-slate-400' : i === active ? 'bg-brand-50 text-brand-800' : 'text-slate-800'
                    }`}
                  >
                    <span className="min-w-0 flex-1">
                      <span className="block truncate font-medium">{p.name}</span>
                      <span className="block truncate text-xs text-slate-500">
                        {[p.code, p.category, describe(p)].filter(Boolean).join(' · ')}
                      </span>
                    </span>
                    {isSelected && <Check size={16} className="shrink-0 text-brand-600" aria-hidden />}
                  </li>
                )
              })}
            </ul>
          </div>,
          document.body,
        )}
    </>
  )
}
