import { Wallet } from 'lucide-react'
import MoneyEntries from '../../components/purchasing/MoneyEntries'

// Supplier payments: Dr the supplier's ledger, Cr Cash / Bank.
export default function Payments() {
  return <MoneyEntries kind="payments" icon={Wallet} />
}
