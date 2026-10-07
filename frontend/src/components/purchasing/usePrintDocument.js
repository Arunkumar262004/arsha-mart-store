import { useRef } from 'react'
import { useReactToPrint } from 'react-to-print'

/** Prints a PrintableDocument on A4 through react-to-print. */
export default function usePrintDocument(documentTitle) {
  const contentRef = useRef(null)
  const print = useReactToPrint({
    contentRef,
    documentTitle,
    pageStyle: `
      @page { size: A4; margin: 12mm; }
      html, body { background: #fff; }
    `,
  })
  return { contentRef, print }
}
