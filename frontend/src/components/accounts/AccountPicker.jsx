import { useEffect, useMemo, useRef, useState } from 'react'
import { ChevronDown, Search } from 'lucide-react'
import { inputClass } from '../ui'

/**
 * Searchable account select: type part of a name or code, pick with the
 * mouse or arrow keys + Enter. `accounts` comes from getAccountOptions().
 */
export default function AccountPicker({ accounts, value, onChange, placeholder = 'Search account…', autoFocus = false }) {
  const selected = accounts.find((a) => a.id === Number(value))
  const [text, setText] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const box = useRef(null)

  const matches = useMemo(() => {
    const q = text.trim().toLowerCase()
    const list = q ? accounts.filter((a) => a.name.toLowerCase().includes(q) || a.code.toLowerCase().includes(q)) : accounts
    return list.slice(0, 60)
  }, [accounts, text])

  // Close when clicking elsewhere.
  useEffect(() => {
    if (!open) return
    const onDown = (e) => box.current && !box.current.contains(e.target) && setOpen(false)
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [open])

  function pick(account) {
    onChange(account.id)
    setText('')
    setOpen(false)
  }

  function onKeyDown(e) {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setOpen(true)
      setActive((i) => Math.min(i + 1, matches.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActive((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter' && open && matches[active]) {
      e.preventDefault()
      pick(matches[active])
    } else if (e.key === 'Escape') {
      setOpen(false)
    }
  }

  return (
    <div ref={box} className="relative">
      <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden />
      <input
        className={`${inputClass} pl-9 pr-8`}
        placeholder={selected ? `${selected.code} · ${selected.name}` : placeholder}
        value={open ? text : selected ? `${selected.code} · ${selected.name}` : text}
        autoFocus={autoFocus}
        onFocus={() => {
          setOpen(true)
          setActive(0)
        }}
        onChange={(e) => {
          setText(e.target.value)
          setOpen(true)
          setActive(0)
        }}
        onKeyDown={onKeyDown}
        role="combobox"
        aria-expanded={open}
      />
      <ChevronDown size={15} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden />
      {open && (
        <ul className="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg" role="listbox">
          {matches.length === 0 && <li className="px-3 py-2 text-slate-400">No matching account</li>}
          {matches.map((a, i) => (
            <li
              key={a.id}
              role="option"
              aria-selected={a.id === Number(value)}
              onMouseDown={(e) => {
                e.preventDefault()
                pick(a)
              }}
              onMouseEnter={() => setActive(i)}
              className={`flex cursor-pointer items-center justify-between gap-3 px-3 py-1.5 ${i === active ? 'bg-brand-50' : ''}`}
            >
              <span className="truncate">
                <span className="font-mono text-xs text-slate-500">{a.code}</span> <span className="text-slate-800">{a.name}</span>
              </span>
              <span className="shrink-0 text-xs text-slate-400">{a.is_party ? 'party' : a.group.replace('_', ' ')}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
