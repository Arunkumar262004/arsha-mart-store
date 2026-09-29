import { Printer } from 'lucide-react'
import { PaperToggle } from '../Bill'
import { Button, Modal } from '../ui'
import ThermalReceipt from './ThermalReceipt'
import usePrintReceipt from './usePrintReceipt'

/** Preview and (re)print the thermal receipt of an existing order. */
export default function ReceiptModal({ order, onClose }) {
  const { contentRef, paper, setPaper, print } = usePrintReceipt(order.order_number)

  return (
    <Modal
      open
      size="md"
      title={`Receipt · ${order.order_number}`}
      onClose={onClose}
      footer={
        <>
          <div className="mr-auto">
            <PaperToggle paper={paper} setPaper={setPaper} />
          </div>
          <Button variant="secondary" onClick={onClose}>Close</Button>
          <Button icon={Printer} onClick={print}>Print</Button>
        </>
      }
    >
      <div className="flex justify-center rounded-xl bg-slate-200 px-3 py-6">
        <div className="shadow-lg">
          <ThermalReceipt ref={contentRef} order={order} paper={paper} />
        </div>
      </div>
    </Modal>
  )
}
