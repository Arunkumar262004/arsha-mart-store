import { ReceiptText } from 'lucide-react'
import MoneyEntries from '../../components/purchasing/MoneyEntries'

// Shop expenses: Dr the expense head (+ Input GST), Cr Cash / Bank.
export default function Expenses() {
  return <MoneyEntries kind="expenses" icon={ReceiptText} />
}
