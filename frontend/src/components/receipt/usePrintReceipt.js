import { useRef, useState } from 'react'
import { useReactToPrint } from 'react-to-print'

const PAPER_KEY = 'store_billing_receipt_paper'

const readPaper = () => {
  try {
    return localStorage.getItem(PAPER_KEY) === '58' ? '58' : '80'
  } catch {
    return '80'
  }
}

/**
 * Prints a ThermalReceipt through react-to-print on a page the size of the
 * roll (80mm or 58mm wide, height = content), so the printer or "Save as
 * PDF" produces a till slip rather than an A4 sheet.
 */
export default function usePrintReceipt(documentTitle) {
  const contentRef = useRef(null)
  const [paper, setPaperState] = useState(readPaper)

  const setPaper = (value) => {
    setPaperState(value)
    try {
      localStorage.setItem(PAPER_KEY, value)
    } catch {
      /* ignore */
    }
  }

  const print = useReactToPrint({
    contentRef,
    documentTitle,
    pageStyle: `
      @page { size: ${paper}mm auto; margin: 0; }
      html, body { margin: 0; padding: 0; background: #fff; }
      body { display: flex; justify-content: center; }
    `,
  })

  return { contentRef, paper, setPaper, print }
}
