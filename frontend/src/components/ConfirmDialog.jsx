import { createContext, useCallback, useContext, useRef, useState } from 'react'
import { TriangleAlert } from 'lucide-react'
import { Button, Modal } from './ui'

const ConfirmContext = createContext(null)

/**
 * In-app replacement for window.confirm:
 *
 *   const confirm = useConfirm()
 *   if (!(await confirm({ title: 'Delete supplier?', message: '...', confirmLabel: 'Delete' }))) return
 *
 * tone: 'danger' (default, red button) or 'primary'.
 */
export function ConfirmProvider({ children }) {
  const [request, setRequest] = useState(null)
  const resolver = useRef(null)

  const confirm = useCallback(
    (options) =>
      new Promise((resolve) => {
        resolver.current?.(false)
        resolver.current = resolve
        setRequest(typeof options === 'string' ? { message: options } : options)
      }),
    [],
  )

  const close = (answer) => {
    resolver.current?.(answer)
    resolver.current = null
    setRequest(null)
  }

  const tone = request?.tone ?? 'danger'

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <Modal
        open={request !== null}
        size="sm"
        title={request?.title ?? 'Are you sure?'}
        onClose={() => close(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => close(false)}>
              {request?.cancelLabel ?? 'Cancel'}
            </Button>
            <Button variant={tone} onClick={() => close(true)} autoFocus>
              {request?.confirmLabel ?? 'Confirm'}
            </Button>
          </>
        }
      >
        <div className="flex gap-3">
          <span
            className={`grid h-10 w-10 shrink-0 place-items-center rounded-full ${tone === 'danger' ? 'bg-red-50 text-red-600' : 'bg-brand-50 text-brand-600'}`}
          >
            <TriangleAlert size={20} aria-hidden />
          </span>
          <p className="pt-2 text-sm text-slate-600">{request?.message}</p>
        </div>
      </Modal>
    </ConfirmContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export const useConfirm = () => useContext(ConfirmContext)
