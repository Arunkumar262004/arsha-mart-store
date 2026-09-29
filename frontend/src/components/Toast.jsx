import { createContext, useCallback, useContext, useState } from 'react'
import { CircleCheck, TriangleAlert, X } from 'lucide-react'

const ToastContext = createContext(() => {})

let nextId = 1

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const dismiss = useCallback((id) => setToasts((all) => all.filter((t) => t.id !== id)), [])

  const toast = useCallback(
    (message, tone = 'success') => {
      const id = nextId++
      setToasts((all) => [...all, { id, message, tone }])
      setTimeout(() => dismiss(id), 4000)
    },
    [dismiss],
  )

  return (
    <ToastContext.Provider value={toast}>
      {children}
      <div className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:right-4 sm:items-end">
        {toasts.map((t) => (
          <div
            key={t.id}
            role="status"
            className="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 shadow-lg"
          >
            {t.tone === 'error' ? (
              <TriangleAlert size={18} className="mt-0.5 shrink-0 text-red-500" aria-hidden />
            ) : (
              <CircleCheck size={18} className="mt-0.5 shrink-0 text-emerald-500" aria-hidden />
            )}
            <span className="flex-1">{t.message}</span>
            <button onClick={() => dismiss(t.id)} className="text-slate-400 hover:text-slate-600" aria-label="Dismiss">
              <X size={16} />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export const useToast = () => useContext(ToastContext)
