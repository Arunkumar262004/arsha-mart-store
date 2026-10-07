import { Button } from '../ui'

/** Prev / Next under a paginated list (Laravel `meta`). */
export default function Pager({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null
  return (
    <div className="flex items-center justify-end gap-3 border-t border-slate-100 px-5 py-3 text-sm text-slate-600">
      <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Prev</Button>
      <span>Page {meta.current_page} of {meta.last_page}</span>
      <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Next</Button>
    </div>
  )
}
