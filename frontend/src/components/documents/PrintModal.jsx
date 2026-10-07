import { Printer } from 'lucide-react'
import { Button, Modal } from '../ui'
import { usePrintA4 } from './printing'

/**
 * A4 preview + Print for a document. `children` is a render function that
 * receives the ref to attach to the printable sheet.
 */
export default function PrintModal({ title, documentTitle, onClose, actions, children }) {
  const { contentRef, print } = usePrintA4(documentTitle)

  return (
    <Modal
      open
      size="xl"
      title={title}
      onClose={onClose}
      footer={
        <>
          {actions && <div className="mr-auto flex flex-wrap gap-2">{actions}</div>}
          <Button variant="secondary" onClick={onClose}>Close</Button>
          <Button icon={Printer} onClick={print}>Print</Button>
        </>
      }
    >
      <div className="overflow-x-auto rounded-xl bg-slate-200 p-3">
        <div className="min-w-[760px] shadow-lg">{children(contentRef)}</div>
      </div>
    </Modal>
  )
}
