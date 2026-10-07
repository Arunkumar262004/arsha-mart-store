import { useState } from 'react'
import { FileSpreadsheet, FileText } from 'lucide-react'
import { downloadAccountsFile } from '../../api/accounts'
import { parseApiError } from '../../api/client'
import { useToast } from '../Toast'
import { Button } from '../ui'

/** Downloads one accounts file (PDF or Excel) with the current filters. */
export default function DownloadButton({ path, params, format = 'pdf', filename, disabled, children }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)

  async function download() {
    setBusy(true)
    try {
      const { blob, filename: name } = await downloadAccountsFile(path, { ...params, format }, filename ?? `report.${format}`)
      const url = URL.createObjectURL(blob)
      const a = Object.assign(document.createElement('a'), { href: url, download: name })
      document.body.appendChild(a)
      a.click()
      a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (e) {
      // Errors arrive as a Blob because of responseType: 'blob'.
      const body = e?.response?.data
      if (body instanceof Blob) {
        try {
          e.response.data = JSON.parse(await body.text())
        } catch {
          /* not JSON */
        }
      }
      toast(parseApiError(e).message, 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Button variant="secondary" icon={format === 'xlsx' ? FileSpreadsheet : FileText} loading={busy} disabled={disabled} onClick={download}>
      {children ?? (format === 'xlsx' ? 'Excel' : 'PDF')}
    </Button>
  )
}
