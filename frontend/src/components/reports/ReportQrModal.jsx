import { useEffect, useState } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import { Check, Copy, Smartphone } from 'lucide-react'
import { getReportShareLink } from '../../api'
import { parseApiError } from '../../api/client'
import { Alert, Button, Modal, Spinner } from '../ui'

const FORMATS = [
  ['pdf', 'PDF'],
  ['xlsx', 'Excel'],
]

const isLocalOnly = (url) => /^https?:\/\/(localhost|127\.0\.0\.1)(:|\/)/.test(url)

/**
 * A QR code for the report with the current filters. Scanning it opens a
 * signed, expiring link that downloads the file on the phone — no login.
 */
export default function ReportQrModal({ report, query, onClose }) {
  const [format, setFormat] = useState('pdf')
  const [link, setLink] = useState(null)
  const [error, setError] = useState(null)
  const [copied, setCopied] = useState(false)
  const queryKey = JSON.stringify(query)

  useEffect(() => {
    let cancelled = false
    setLink(null)
    setError(null)
    getReportShareLink(report, JSON.parse(queryKey), format)
      .then((data) => !cancelled && setLink(data))
      .catch((e) => !cancelled && setError(parseApiError(e).message))
    return () => {
      cancelled = true
    }
  }, [report, queryKey, format])

  async function copy() {
    try {
      await navigator.clipboard.writeText(link.url)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch {
      /* clipboard blocked */
    }
  }

  const expires = link && new Date(link.expires_at).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' })

  return (
    <Modal open size="sm" title="Download on your phone" onClose={onClose} footer={<Button variant="secondary" onClick={onClose}>Close</Button>}>
      <div className="flex flex-col items-center gap-4 text-center">
        <div className="inline-flex rounded-lg border border-slate-200 p-1">
          {FORMATS.map(([value, label]) => (
            <button
              key={value}
              type="button"
              onClick={() => setFormat(value)}
              className={`rounded-md px-4 py-1.5 text-sm font-medium transition ${format === value ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
            >
              {label}
            </button>
          ))}
        </div>

        {error && <Alert>{error}</Alert>}

        <div className="grid h-[244px] w-[244px] place-items-center rounded-2xl border border-slate-200 bg-white p-3">
          {link ? <QRCodeSVG value={link.url} size={216} marginSize={0} /> : !error && <Spinner size={24} />}
        </div>

        <p className="flex items-center gap-2 text-sm text-slate-600">
          <Smartphone size={16} className="text-slate-400" aria-hidden />
          Scan with your phone camera — the {format === 'pdf' ? 'PDF' : 'Excel file'} downloads straight away.
        </p>

        {link && (
          <>
            <p className="text-xs text-slate-500">
              No login needed. Anyone with this code can download the report until <span className="font-medium text-slate-700">{expires}</span>.
            </p>
            {isLocalOnly(link.url) && (
              <Alert tone="warning">
                This link points to <strong>localhost</strong>, which a phone can't open. Set <code>REPORT_LINK_URL</code> in
                backend/.env to this PC's network address (e.g. http://192.168.1.20:8000) and run{' '}
                <code>php artisan serve --host=0.0.0.0</code>.
              </Alert>
            )}
            <Button variant="ghost" size="sm" icon={copied ? Check : Copy} onClick={copy}>
              {copied ? 'Copied' : 'Copy link'}
            </Button>
          </>
        )}
      </div>
    </Modal>
  )
}
