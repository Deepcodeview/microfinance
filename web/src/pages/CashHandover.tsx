import { useState, type FormEvent } from 'react'
import { api } from '../api'
import { useAuth } from '../auth'
import { Details, ErrorBanner, Field, Loading, Modal, Notice, PageHeader, Pager, Segmented, StatusBadge, bannerError, fieldErrors } from '../components/ui'
import { date, dateTime, money, titleCase } from '../format'
import { useFetch, useLocalPagedList, usePagedList } from '../hooks'
import type { Handover } from '../types'

interface CashPosition {
  collections: string
  floats: string
  field_disbursements: string
  in_hand: string
}

interface Movement {
  id: string
  movement_number: string
  type: 'float_issued' | 'field_disbursement'
  amount: string
  business_date: string
  note: string | null
  loan_number: string | null
  customer_name?: string | null
  agent_name?: string
  created_by_name: string
  handover_number?: string | null
}

const MOVEMENT_LABEL: Record<Movement['type'], string> = { float_issued: 'Float from branch', field_disbursement: 'Loan paid out' }

interface Summary {
  business_date: string
  expected_cash: string
  position: CashPosition
  movements: Movement[]
  payments: { id: string; payment_number: string; amount: string; collected_at: string; customer_name: string }[]
  today_handover: Handover | null
}

export function CashHandover() {
  const { user, can } = useAuth()
  const summary = useFetch<Summary>('/cash/my-summary')
  // Field collectors always see their own cash; office roles only when they actually took cash themselves.
  const holdsCash = user?.customer_scope === 'assigned' || Number(summary.data?.expected_cash ?? 0) > 0 || Boolean(summary.data?.today_handover)
  const list = usePagedList<Handover>('/cash-handovers')
  const [selected, setSelected] = useState<Handover | null>(null)
  const [notice, setNotice] = useState('')

  return (
    <>
      <PageHeader title="Cash handover" subtitle="Collected cash stays with the collector until a supervisor counts it into branch cash." />
      {notice ? <Notice>{notice}</Notice> : null}

      {holdsCash || !can('cash.verify') ? <MyHandover
        summary={summary.data}
        error={summary.error}
        onSubmitted={() => {
          summary.reload()
          list.reload()
          setNotice('Handover submitted for verification.')
        }}
      /> : null}

      {can('cash.float') ? <AgentFloats onIssued={(message) => setNotice(message)} /> : null}

      <h2 className="form-section" style={{ marginTop: holdsCash || !can('cash.verify') ? 32 : 0 }}>
        {can('cash.verify') ? 'Handovers to verify' : 'Your handovers'}
      </h2>
      <div className="toolbar">
        <Segmented
          options={[
            { id: '', label: 'All' },
            { id: 'submitted', label: 'To verify' },
            { id: 'discrepancy', label: 'Discrepancies' },
            { id: 'verified', label: 'Verified' },
            { id: 'resolved', label: 'Resolved' },
          ]}
          value={list.params.get('status') ?? ''}
          onChange={(status) => list.update({ status })}
        />
      </div>
      <ErrorBanner error={list.error} />
      {list.rows === null && !list.error ? <Loading /> : null}
      {list.rows ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Handover</th>
                <th>Collector</th>
                <th>Date</th>
                <th className="num">Expected</th>
                <th className="num">Counted</th>
                <th className="num">Difference</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {list.rows.length === 0 ? (
                <tr>
                  <td colSpan={8} className="table__empty">
                    No handovers.
                  </td>
                </tr>
              ) : (
                list.rows.map((h) => (
                  <tr key={h.id} className={h.status === 'discrepancy' ? 'is-highlight' : undefined}>
                    <td className="mono">{h.handover_number}</td>
                    <td>{h.agent_name}</td>
                    <td>{date(h.business_date)}</td>
                    <td className="num">{money(h.expected_cash)}</td>
                    <td className="num">{money(h.verified_cash)}</td>
                    <td className="num" style={{ color: Number(h.difference ?? 0) < 0 ? 'var(--danger)' : undefined }}>
                      {h.difference === null ? '—' : money(h.difference)}
                    </td>
                    <td>
                      <StatusBadge status={h.status} />
                    </td>
                    <td>
                      <button type="button" className="btn btn--small" onClick={() => setSelected(h)}>
                        {h.status === 'submitted' && can('cash.verify') ? 'Verify' : h.status === 'discrepancy' && can('cash.resolve') ? 'Resolve' : 'View'}
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      ) : null}
      {list.meta ? <Pager page={list.meta.page} totalPages={list.meta.total_pages} onPage={(p) => list.update({ page: String(p) })} /> : null}

      {selected ? (
        <HandoverModal
          handover={selected}
          onClose={() => setSelected(null)}
          onDone={(message) => {
            setSelected(null)
            list.reload()
            setNotice(message)
          }}
        />
      ) : null}
    </>
  )
}

function MyHandover({ summary, error, onSubmitted }: { summary: Summary | null; error: unknown; onSubmitted: () => void }) {
  const [declared, setDeclared] = useState('')
  const [note, setNote] = useState('')
  const [submitError, setSubmitError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)

  if (!summary) return error ? <ErrorBanner error={error} /> : <Loading />

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setSubmitError(null)
    try {
      await api.post('/cash-handovers', { declared_cash: declared, note: note || null })
      setDeclared('')
      setNote('')
      onSubmitted()
    } catch (err) {
      setSubmitError(err)
    } finally {
      setSaving(false)
    }
  }

  const today = summary.today_handover
  return (
    <div className="grid grid--2-1">
      <section className="card">
        <div className="card__header">
          <h2 className="card__title">Cash in your hand</h2>
          <span className="muted small">{date(summary.business_date)}</span>
        </div>
        <div className="highlight">
          <div className="highlight__label">Expected per system</div>
          <div className="highlight__value">{money(summary.expected_cash)}</div>
        </div>
        {Number(summary.position.floats) > 0 || Number(summary.position.field_disbursements) > 0 ? (
          <Details
            compact
            rows={[
              ['Cash collected', money(summary.position.collections)],
              ['+ Float from branch', money(summary.position.floats)],
              ['− Loans paid out', money(summary.position.field_disbursements)],
              [<strong key="l">= Cash in hand</strong>, <strong key="v">{money(summary.position.in_hand)}</strong>],
            ]}
          />
        ) : null}
        {summary.movements.length > 0 ? (
          <div className="table-wrap table-wrap--plain">
            <table className="table table--compact">
              <tbody>
                {summary.movements.map((m) => (
                  <tr key={m.id}>
                    <td>{MOVEMENT_LABEL[m.type]}</td>
                    <td className="small muted">{m.loan_number ? `${m.loan_number} · ${m.customer_name ?? ''}` : m.note ?? `by ${m.created_by_name}`}</td>
                    <td className="num">{m.type === 'field_disbursement' ? '−' : '+'}{money(m.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
        {summary.payments.length > 0 ? (
          <div className="table-wrap table-wrap--plain table-wrap--scroll">
            <table className="table table--compact">
              <tbody>
                {summary.payments.map((p) => (
                  <tr key={p.id}>
                    <td className="mono small">{p.payment_number}</td>
                    <td>{p.customer_name}</td>
                    <td className="small muted">{dateTime(p.collected_at)}</td>
                    <td className="num">{money(p.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="muted">No cash collections waiting to be handed over.</p>
        )}
      </section>

      <section className="card">
        <h2 className="card__title" style={{ marginBottom: 12 }}>
          Day-end handover
        </h2>
        {today ? (
          <>
            <p>
              You submitted <span className="mono">{today.handover_number}</span> today.
            </p>
            <Details
              compact
              rows={[
                ['Declared', money(today.declared_cash)],
                ['Status', <StatusBadge key="s" status={today.status} />],
              ]}
            />
          </>
        ) : (
          <form onSubmit={submit} className="stack">
            <ErrorBanner error={bannerError(submitError)} />
            <Field label="Cash you are handing over (₹)" error={fieldErrors(submitError).declared_cash}>
              <input inputMode="decimal" value={declared} onChange={(e) => setDeclared(e.target.value)} placeholder={String(Number(summary.expected_cash))} />
            </Field>
            <Field label="Note" error={fieldErrors(submitError).note}>
              <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Optional" />
            </Field>
            <button type="submit" className="btn btn--primary" disabled={saving || declared === ''}>
              Submit handover
            </button>
          </form>
        )}
      </section>
    </div>
  )
}

interface AgentCashRow extends CashPosition {
  id: string
  name: string
  branch_name: string
}

/** Cash managers: what each agent holds, giving a float, and the float / field payout history. */
function AgentFloats({ onIssued }: { onIssued: (message: string) => void }) {
  const agents = useFetch<AgentCashRow[]>('/cash/agents')
  const history = useLocalPagedList<Movement>('/cash/movements', 10)
  const [giving, setGiving] = useState<AgentCashRow | null>(null)
  return (
    <>
      <h2 className="form-section">Agents' cash</h2>
      <p className="small muted" style={{ marginTop: -6 }}>Give an agent a cash float to pay out approved loans in the field. Whatever they don't pay out comes back in their day-end handover.</p>
      <ErrorBanner error={agents.error} />
      {agents.data ? (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Agent</th><th>Branch</th><th className="num">Collected</th><th className="num">Float</th><th className="num">Loans paid out</th><th className="num">Cash in hand</th><th /></tr></thead>
            <tbody>
              {agents.data.length === 0 ? <tr><td colSpan={7} className="table__empty">No collection agents yet.</td></tr> : agents.data.map((a) => (
                <tr key={a.id}>
                  <td>{a.name}</td>
                  <td>{a.branch_name}</td>
                  <td className="num">{money(a.collections)}</td>
                  <td className="num">{money(a.floats)}</td>
                  <td className="num">{money(a.field_disbursements)}</td>
                  <td className="num"><strong>{money(a.in_hand)}</strong></td>
                  <td><button type="button" className="btn btn--small" onClick={() => setGiving(a)}>Give float</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : <Loading />}
      {history.rows && history.rows.length > 0 ? (
        <details className="card" style={{ marginTop: 12 }}>
          <summary><strong>Floats &amp; field payouts</strong></summary>
          <div className="table-wrap table-wrap--plain" style={{ marginTop: 10 }}>
            <table className="table table--compact">
              <thead><tr><th>Number</th><th>Date</th><th>Agent</th><th>Type</th><th>Details</th><th className="num">Amount</th><th>Handover</th></tr></thead>
              <tbody>
                {history.rows.map((m) => (
                  <tr key={m.id}>
                    <td className="mono small">{m.movement_number}</td>
                    <td>{date(m.business_date)}</td>
                    <td>{m.agent_name}</td>
                    <td>{MOVEMENT_LABEL[m.type]}</td>
                    <td className="small muted">{m.loan_number ?? m.note ?? `by ${m.created_by_name}`}</td>
                    <td className="num">{money(m.amount)}</td>
                    <td className="mono small">{m.handover_number ?? <span className="muted">Not yet</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {history.meta ? <Pager page={history.page} totalPages={history.meta.total_pages} onPage={history.setPage} /> : null}
        </details>
      ) : null}
      {giving ? (
        <FloatModal
          agent={giving}
          onClose={() => setGiving(null)}
          onDone={(amount) => {
            onIssued(`${money(amount)} float given to ${giving.name}. It is part of their day-end handover.`)
            setGiving(null)
            agents.reload()
            history.reload()
          }}
        />
      ) : null}
    </>
  )
}

function FloatModal({ agent, onClose, onDone }: { agent: AgentCashRow; onClose: () => void; onDone: (amount: string) => void }) {
  const [amount, setAmount] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await api.post('/cash/floats', { agent_user_id: Number(agent.id), amount, note: note || null })
      onDone(amount)
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }
  return (
    <Modal title={`Give cash float to ${agent.name}`} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <ErrorBanner error={bannerError(error)} />
        <p className="small muted">Moves cash from branch cash to the agent. They now hold {money(agent.in_hand)}.</p>
        <Field label="Amount (₹) *" error={fieldErrors(error).amount}><input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus /></Field>
        <Field label="Note" error={fieldErrors(error).note}><input value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. For LN00000012 payout" maxLength={255} /></Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={saving || !Number(amount)}>{saving ? 'Saving…' : `Give ${amount ? money(amount) : 'float'}`}</button>
        </div>
      </form>
    </Modal>
  )
}

function HandoverModal({ handover, onClose, onDone }: { handover: Handover; onClose: () => void; onDone: (message: string) => void }) {
  const { user, can } = useAuth()
  const { data: h } = useFetch<Handover>(`/cash-handovers/${handover.id}`)
  const [counted, setCounted] = useState('')
  const [resolution, setResolution] = useState('recovered')
  const [note, setNote] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)

  const canVerify = handover.status === 'submitted' && can('cash.verify') && (Number(handover.agent_id) !== user?.id || Boolean(user?.settings.self_approval))
  const canResolve = handover.status === 'discrepancy' && can('cash.resolve')
  const shortage = Number(handover.difference ?? 0) < 0

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      if (canVerify) {
        await api.post(`/cash-handovers/${handover.id}/verify`, { verified_cash: counted, note: note || null })
        onDone('Handover verified and posted to branch cash.')
      } else {
        await api.post(`/cash-handovers/${handover.id}/resolve`, { resolution, note })
        onDone('Discrepancy resolved.')
      }
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={`Handover ${handover.handover_number}`} onClose={onClose} wide>
      <Details
        compact
        rows={[
          ['Collector', handover.agent_name],
          ['Business date', date(handover.business_date)],
          ['Expected (system)', money(handover.expected_cash)],
          ['Declared by collector', money(handover.declared_cash)],
          ['Counted', handover.verified_cash ? `${money(handover.verified_cash)} by ${handover.verified_by_name}` : '—'],
          ['Difference', handover.difference === null ? '—' : money(handover.difference)],
          ['Resolution', handover.resolution ? `${titleCase(handover.resolution)} by ${handover.resolved_by_name}: ${handover.resolution_note}` : '—'],
        ]}
      />
      {h?.payments && h.payments.length > 0 ? (
        <details>
          <summary className="small muted" style={{ cursor: 'pointer' }}>
            {h.payments.length} payments in this handover
          </summary>
          <table className="table table--compact">
            <tbody>
              {h.payments.map((p) => (
                <tr key={p.id}>
                  <td className="mono small">{p.payment_number}</td>
                  <td>{p.customer_name}</td>
                  <td className="num">{money(p.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </details>
      ) : null}

      {h?.movements && h.movements.length > 0 ? (
        <div className="small">
          {h.movements.map((m) => (
            <div key={m.id} className="row row--between">
              <span>{MOVEMENT_LABEL[m.type]}{m.loan_number ? ` · ${m.loan_number}` : ''}</span>
              <span>{m.type === 'field_disbursement' ? '−' : '+'}{money(m.amount)}</span>
            </div>
          ))}
          <p className="muted" style={{ marginTop: 4 }}>Expected cash already includes these.</p>
        </div>
      ) : null}

      {canVerify || canResolve ? (
        <form onSubmit={submit} className="stack">
          <ErrorBanner error={bannerError(error)} />
          {canVerify ? (
            <Field label="Cash counted (₹)" error={errors.verified_cash} hint="Count the notes physically. Any difference stays open until resolved.">
              <input inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} autoFocus />
            </Field>
          ) : (
            <Field label="Resolution" error={errors.resolution}>
              <select value={resolution} onChange={(e) => setResolution(e.target.value)}>
                {shortage ? (
                  <>
                    <option value="recovered">Shortage recovered from collector</option>
                    <option value="written_off">Write off shortage (expense)</option>
                  </>
                ) : (
                  <option value="overage_income">Take overage to income</option>
                )}
              </select>
            </Field>
          )}
          <Field label={canResolve ? 'Note *' : 'Note'} error={errors.note}>
            <input value={note} onChange={(e) => setNote(e.target.value)} />
          </Field>
          <div className="modal__footer">
            <button type="button" className="btn btn--ghost" onClick={onClose}>
              Cancel
            </button>
            <button type="submit" className="btn btn--primary" disabled={saving || (canVerify && counted === '')}>
              {canVerify ? 'Verify' : 'Resolve'}
            </button>
          </div>
        </form>
      ) : handover.status === 'submitted' && Number(handover.agent_id) === user?.id ? (
        <p className="muted">Waiting for a supervisor to count and verify.</p>
      ) : null}
    </Modal>
  )
}
