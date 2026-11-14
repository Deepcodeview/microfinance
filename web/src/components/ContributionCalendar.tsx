import { useState } from 'react'
import { date, money } from '../format'
import { locale } from '../i18n'
import { periodCount } from '../plans'
import type { ContributionDay, ContributionStatus, DepositTerms } from '../types'

const STATE_LABEL: Record<ContributionDay['state'], string> = {
  paid: 'Paid on time',
  paid_late: 'Paid late',
  brought_in: 'From old register',
  missed: 'Missed',
  unpaid: 'Not paid',
  due: 'Due now',
  upcoming: 'Upcoming',
}

/**
 * A recurring savings account installment by installment: which were paid on time, paid late (catch-up),
 * missed, due now or still to come. One cell per day (daily savers) or per week / month.
 */
export function ContributionCalendar({ days, status, frequency, installment }: {
  days: ContributionDay[]
  status: ContributionStatus | undefined
  frequency: DepositTerms['contribution_frequency']
  installment: string | null
}) {
  const [picked, setPicked] = useState<ContributionDay | null>(null)
  const months = new Map<string, ContributionDay[]>()
  for (const d of days) {
    const key = d.date.slice(0, 7)
    months.set(key, [...(months.get(key) ?? []), d])
  }
  const count = (s: ContributionDay['state'][]) => days.filter((d) => s.includes(d.state)).length

  return (
    <section className="card">
      <h2 className="card__title">Collection record</h2>
      {status ? (
        <div className={`contrib-status${status.installments_due > 1 ? ' contrib-status--late' : ''}`}>
          {status.installments_due === 0 ? (
            <><strong>Paid up to date</strong> · next collection from {date(status.next_due_date)}</>
          ) : status.missed_installments > 0 ? (
            <>
              <strong>Not paid for {periodCount(status.missed_installments, frequency)}</strong> (since {date(status.unpaid_since)}) ·
              {' '}<strong>{money(status.amount_due)} due</strong> = {status.installments_due} × {money(installment)}
            </>
          ) : (
            <><strong>{money(status.amount_due)} due</strong> {frequency === 'daily' ? 'today' : frequency === 'weekly' ? 'this week' : 'this month'}</>
          )}
          {status.last_paid_on ? <div className="small muted">Last paid {date(status.last_paid_on)}</div> : null}
        </div>
      ) : null}
      <div className="contrib-counts small">
        <span><i className="contrib-dot contrib--paid" />{count(['paid', 'brought_in'])} on time</span>
        <span><i className="contrib-dot contrib--paid_late" />{count(['paid_late'])} paid late</span>
        <span><i className="contrib-dot contrib--missed" />{count(['missed', 'unpaid'])} missed</span>
        <span><i className="contrib-dot contrib--upcoming" />{count(['upcoming', 'due'])} to go</span>
      </div>
      <div className="contrib-months">
        {[...months.entries()].map(([month, list]) => (
          <div key={month} className="contrib-month">
            <div className="contrib-month__name small muted">
              {new Date(`${month}-01T00:00:00`).toLocaleDateString(locale(), { month: 'long', year: 'numeric' })}
            </div>
            <div className={`contrib-grid${frequency === 'daily' ? ' contrib-grid--week' : ''}`}>
              {frequency === 'daily'
                ? Array.from({ length: (new Date(`${list[0].date}T00:00:00`).getDay() + 6) % 7 }, (_, i) => <span key={`pad${i}`} />)
                : null}
              {list.map((d) => (
                <button
                  type="button"
                  key={d.date}
                  className={`contrib-cell contrib--${d.state}${picked?.date === d.date ? ' is-picked' : ''}`}
                  onClick={() => setPicked(d)}
                  title={`${date(d.date)}: ${STATE_LABEL[d.state]}${d.paid_on && d.state === 'paid_late' ? ` (paid ${date(d.paid_on)})` : ''}`}
                >
                  {Number(d.date.slice(8, 10))}
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>
      {picked ? (
        <div className="contrib-picked small">
          <strong>{date(picked.date)}</strong> · {STATE_LABEL[picked.state]}{picked.paid_on && picked.state === 'paid_late' ? `, paid on ${date(picked.paid_on)}` : ''}
        </div>
      ) : null}
      <p className="small muted" style={{ marginBottom: 0 }}>Missed installments can be paid later; each payment fills the oldest unpaid ones first. Tap a day to see when it was paid.</p>
    </section>
  )
}
