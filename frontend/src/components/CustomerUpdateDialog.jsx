import { ArrowRight, UserPlus, UserRoundCheck, UserRoundPen } from 'lucide-react'
import { Modal } from './ui'

/**
 * Shown when the billing form differs from the matched saved customer
 * (e.g. Arun's mobile with a new email). Every choice continues straight to
 * billing; the counter is never left at a dead end.
 *
 * onChoose(override) is called with the extra order fields for the choice.
 */
export default function CustomerUpdateDialog({ match, changes, form, onChoose, onCancel }) {
  const saved = match.customer
  const emailChanged = changes.some((c) => c.field === 'email')

  const options = [
    {
      key: 'update',
      icon: UserRoundPen,
      title: `Update ${saved.name}'s details & bill`,
      detail: 'Saves the new details on this customer. Recommended when it is the same person.',
      recommended: true,
      override: { customer_id: saved.id, update_customer: true },
    },
    match.by === 'phone' && emailChanged
      ? {
          key: 'separate',
          icon: UserPlus,
          title: `Bill ${form.email} without this mobile`,
          detail: `Keeps ${saved.name}'s record unchanged. The bill goes to ${form.email} with no WhatsApp message.`,
          override: { customer_phone: null },
        }
      : {
          key: 'keep',
          icon: UserRoundCheck,
          title: `Bill ${saved.name} with saved details`,
          detail: 'Ignores the edits for this bill; confirmations go to the saved email and mobile.',
          override: { customer_id: saved.id, update_customer: false },
        },
  ]

  return (
    <Modal open size="md" title="This customer already exists" onClose={onCancel}>
      <p className="text-sm text-slate-600">
        The {match.by === 'phone' ? 'mobile number' : 'email'} you entered belongs to <b className="text-slate-900">{saved.name}</b>, but some
        details are different:
      </p>

      <div className="mt-4 overflow-hidden rounded-xl border border-slate-200">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-4 py-2">Field</th>
              <th className="px-4 py-2">Saved</th>
              <th className="w-6 px-0 py-2" />
              <th className="px-4 py-2">New</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {changes.map((c) => (
              <tr key={c.field}>
                <td className="px-4 py-2.5 text-slate-500">{c.label}</td>
                <td className="break-all px-4 py-2.5 text-slate-500 line-through decoration-slate-300">{c.saved}</td>
                <td className="px-0 py-2.5 text-slate-300">
                  <ArrowRight size={14} aria-hidden />
                </td>
                <td className="break-all px-4 py-2.5 font-medium text-slate-900">{c.next}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-5 space-y-2">
        {options.map(({ key, icon: Icon, title, detail, recommended, override }) => (
          <button
            key={key}
            type="button"
            onClick={() => onChoose(override)}
            className={`flex w-full items-start gap-3 rounded-xl border p-4 text-left transition ${
              recommended
                ? 'border-brand-300 bg-brand-50/60 hover:border-brand-500 hover:bg-brand-50'
                : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
            }`}
          >
            <span className={`rounded-lg p-2 ${recommended ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'}`}>
              <Icon size={18} aria-hidden />
            </span>
            <span className="min-w-0 flex-1">
              <span className="flex flex-wrap items-center gap-2 break-all text-sm font-semibold text-slate-900">
                {title}
                {recommended && (
                  <span className="rounded-full bg-brand-100 px-2 py-0.5 text-[11px] font-medium text-brand-700">Recommended</span>
                )}
              </span>
              <span className="mt-0.5 block text-xs text-slate-500">{detail}</span>
            </span>
          </button>
        ))}
      </div>

      <button type="button" onClick={onCancel} className="mt-4 text-sm font-medium text-slate-500 hover:text-slate-700">
        ← Back to edit
      </button>
    </Modal>
  )
}
