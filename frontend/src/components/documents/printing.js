import { useRef } from 'react'
import { useReactToPrint } from 'react-to-print'

/**
 * Prints an A4 document component through react-to-print. Attach
 * `contentRef` to the sheet; `print()` opens the browser's print dialog
 * (which also offers "Save as PDF").
 */
export function usePrintA4(documentTitle) {
  const contentRef = useRef(null)
  const print = useReactToPrint({
    contentRef,
    documentTitle: (documentTitle ?? 'document').replaceAll('/', '-'),
    pageStyle: `
      @page { size: A4; margin: 10mm; }
      html, body { margin: 0; padding: 0; background: #fff; }
    `,
  })
  return { contentRef, print }
}

/** Save a downloaded blob under its filename. */
export function saveBlob({ blob, filename }) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export const money = (value) =>
  Number(value ?? 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const rate = (value) => `${Number(value ?? 0)}%`

export const formatDate = (value, withTime = false) =>
  value
    ? new Date(value).toLocaleString('en-IN', withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' })
    : '-'

/** A date as YYYY-MM-DD in the browser's time zone (for <input type="date">). */
export const localDate = (date = new Date()) => {
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/** Store address on one line. */
export const storeAddress = (store) =>
  [store?.address, store?.city, store?.state && store?.pincode ? `${store.state} - ${store.pincode}` : store?.state || store?.pincode]
    .filter(Boolean)
    .join(', ')
