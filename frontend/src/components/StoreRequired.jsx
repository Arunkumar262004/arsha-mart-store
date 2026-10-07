import { useAuth } from '../auth/AuthContext'
import { Alert } from './ui'

/**
 * Shown on pages that create documents while "All stores" is selected: new
 * documents need one store. Renders nothing when a store is selected.
 *
 *   <StoreRequired what="bills" />  ->  "Select a store in the header to create bills."
 */
export default function StoreRequired({ what = 'documents' }) {
  const { isAllStores } = useAuth()
  if (!isAllStores) return null
  return <Alert tone="warning">You are viewing all stores. Select a store in the header to create {what}.</Alert>
}
