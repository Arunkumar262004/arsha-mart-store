import { HandCoins } from 'lucide-react'
import MoneyEntries from '../../components/purchasing/MoneyEntries'

// Customer receipts: Dr Cash / Bank, Cr the customer's ledger.
export default function Receipts() {
  return <MoneyEntries kind="receipts" icon={HandCoins} />
}
