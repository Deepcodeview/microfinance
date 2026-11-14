import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorBanner, Field, Loading, PageHeader, fieldErrors } from '../components/ui'
import type { Agent, Branch, Customer } from '../types'

const EMPTY = {
  full_name: '',
  phone: '',
  alt_phone: '',
  email: '',
  date_of_birth: '',
  gender: '',
  address_line: '',
  city: '',
  state: '',
  pincode: '',
  occupation: '',
  monthly_income: '',
  status: 'active',
  branch_id: '',
  agent_user_id: '',
}

type FormState = typeof EMPTY

export function CustomerForm() {
  const { id } = useParams()
  const editing = id !== undefined
  const navigate = useNavigate()
  const { user, can } = useAuth()

  const [form, setForm] = useState<FormState>(EMPTY)
  const [loaded, setLoaded] = useState(!editing)
  const [branches, setBranches] = useState<Branch[]>([])
  const [agents, setAgents] = useState<Agent[]>([])
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)

  const canAssign = !editing && can('customers.assign')
  const pickBranch = !editing && user?.customer_scope === 'all'

  useEffect(() => {
    if (editing) {
      api
        .get<Customer>(`/customers/${id}`)
        .then((c) => {
          const next = { ...EMPTY }
          for (const key of Object.keys(EMPTY) as (keyof FormState)[]) {
            const value = (c as unknown as Record<string, unknown>)[key]
            if (typeof value === 'string') next[key] = value
          }
          next.monthly_income = c.monthly_income ? String(Number(c.monthly_income)) : ''
          setForm(next)
          setLoaded(true)
        })
        .catch(setError)
      return
    }
    if (pickBranch) api.get<Branch[]>('/branches').then(setBranches).catch(setError)
    if (canAssign) api.get<Agent[]>('/agents').then(setAgents).catch(setError)
  }, [editing, id, pickBranch, canAssign])

  const errors = fieldErrors(error)
  const set = (key: keyof FormState) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const visibleAgents = pickBranch && form.branch_id ? agents.filter((a) => a.branch_id === form.branch_id) : agents

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError(null)

    const { branch_id, agent_user_id, status, ...profile } = form
    // Empty optional fields are sent as null so they can be cleared on edit.
    const body: Record<string, unknown> = Object.fromEntries(
      Object.entries(profile).map(([k, v]) => [k, v.trim() === '' ? null : v.trim()]),
    )
    if (editing) body.status = status
    if (pickBranch && branch_id) body.branch_id = Number(branch_id)
    if (canAssign && agent_user_id) body.agent_user_id = Number(agent_user_id)

    try {
      const saved = editing
        ? await api.patch<Customer>(`/customers/${id}`, body)
        : await api.post<Customer>('/customers', body)
      // A new customer goes straight to KYC capture (photo, Aadhaar, PAN with the phone camera).
      navigate(id ? `/customers/${saved.id}` : `/customers/${saved.id}?kyc=1`)
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  if (!loaded) return error ? <ErrorBanner error={error} /> : <Loading />

  return (
    <>
      <PageHeader title={editing ? 'Edit customer' : 'New customer'} />
      <form className="card" onSubmit={onSubmit} noValidate>
        <ErrorBanner error={error} />

        <h2 className="form-section">Personal details</h2>
        <div className="grid grid--2">
          <Field label="Full name *" error={errors.full_name}>
            <input value={form.full_name} onChange={set('full_name')} />
          </Field>
          <Field label="Date of birth" error={errors.date_of_birth}>
            <input type="date" value={form.date_of_birth} onChange={set('date_of_birth')} />
          </Field>
          <Field label="Gender" error={errors.gender}>
            <select value={form.gender} onChange={set('gender')}>
              <option value="">Not specified</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
          </Field>
          <Field label="Occupation" error={errors.occupation}>
            <input value={form.occupation} onChange={set('occupation')} />
          </Field>
          <Field label="Monthly income (₹)" error={errors.monthly_income}>
            <input inputMode="decimal" value={form.monthly_income} onChange={set('monthly_income')} />
          </Field>
        </div>

        <h2 className="form-section">Contact</h2>
        <div className="grid grid--2">
          <Field label="Mobile number *" error={errors.phone}>
            <input type="tel" value={form.phone} onChange={set('phone')} />
          </Field>
          <Field label="Alternate number" error={errors.alt_phone}>
            <input type="tel" value={form.alt_phone} onChange={set('alt_phone')} />
          </Field>
          <Field label="Email" error={errors.email}>
            <input type="email" value={form.email} onChange={set('email')} />
          </Field>
        </div>

        <h2 className="form-section">Address</h2>
        <div className="grid grid--2">
          <Field label="Address *" error={errors.address_line}>
            <input value={form.address_line} onChange={set('address_line')} />
          </Field>
          <Field label="City *" error={errors.city}>
            <input value={form.city} onChange={set('city')} />
          </Field>
          <Field label="State *" error={errors.state}>
            <input value={form.state} onChange={set('state')} />
          </Field>
          <Field label="PIN code *" error={errors.pincode}>
            <input inputMode="numeric" maxLength={6} value={form.pincode} onChange={set('pincode')} />
          </Field>
        </div>

        {pickBranch || canAssign || editing ? <h2 className="form-section">Account management</h2> : null}
        <div className="grid grid--2">
          {pickBranch ? (
            <Field label="Branch *" error={errors.branch_id}>
              <select value={form.branch_id} onChange={(e) => setForm((f) => ({ ...f, branch_id: e.target.value, agent_user_id: '' }))}>
                <option value="">Select branch</option>
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name} ({b.code})
                  </option>
                ))}
              </select>
            </Field>
          ) : null}
          {canAssign ? (
            <Field label="Collection agent" error={errors.agent_user_id} hint="Can be assigned later.">
              <select value={form.agent_user_id} onChange={set('agent_user_id')}>
                <option value="">Unassigned</option>
                {visibleAgents.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name} — {a.branch_name}
                  </option>
                ))}
              </select>
            </Field>
          ) : null}
          {editing ? (
            <Field label="Status" error={errors.status}>
              <select value={form.status} onChange={set('status')}>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="blocked">Blocked</option>
              </select>
            </Field>
          ) : null}
        </div>

        <div className="form__actions">
          <Link to={editing ? `/customers/${id}` : '/customers'} className="btn btn--ghost">
            Cancel
          </Link>
          <button type="submit" className="btn btn--primary" disabled={saving}>
            {saving ? 'Saving…' : editing ? 'Save changes' : 'Create customer'}
          </button>
        </div>
      </form>
    </>
  )
}
