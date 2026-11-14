import { useState, type FormEvent } from 'react'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorBanner, Field, Loading, Modal, Notice, PageHeader, Pager, StatusBadge, bannerError, fieldErrors } from '../components/ui'
import { dateTime, titleCase } from '../format'
import { useFetch, usePagedList } from '../hooks'
import type { Branch } from '../types'

interface StaffUser {
  id: string
  name: string
  email: string
  phone: string | null
  status: 'active' | 'disabled'
  branch_id: string | null
  branch_name: string | null
  last_login_at: string | null
  roles: string | string[] | null
  mfa_enabled: string | boolean
}

const roleList = (r: StaffUser['roles']) => (Array.isArray(r) ? r : r ? r.split(',') : [])

export function Users() {
  const { rows, meta, error, params, update, reload } = usePagedList<StaffUser>('/users')
  const { data: roles } = useFetch<{ role_key: string; name: string }[]>('/roles')
  const [editing, setEditing] = useState<StaffUser | 'new' | null>(null)
  const [search, setSearch] = useState(params.get('q') ?? '')
  const [notice, setNotice] = useState('')

  return (
    <>
      <PageHeader
        title="Staff"
        subtitle="Staff accounts and their roles. Customer portal logins are managed from each customer's page."
        actions={
          <button type="button" className="btn btn--primary" onClick={() => setEditing('new')}>
            Add staff
          </button>
        }
      />
      {notice ? <Notice>{notice}</Notice> : null}
      <form className="toolbar" onSubmit={(e) => { e.preventDefault(); update({ q: search.trim() }) }}>
        <input type="search" placeholder="Name or email" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select value={params.get('role') ?? ''} onChange={(e) => update({ role: e.target.value })}>
          <option value="">All roles</option>
          {(roles ?? []).map((r) => <option key={r.role_key} value={r.role_key}>{r.name}</option>)}
        </select>
        <select value={params.get('status') ?? ''} onChange={(e) => update({ status: e.target.value })}>
          <option value="">Any status</option>
          <option value="active">Active</option>
          <option value="disabled">Disabled</option>
        </select>
      </form>
      <ErrorBanner error={error} />
      {rows === null && !error ? <Loading /> : null}
      {rows ? (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Name</th><th>Roles</th><th>Branch</th><th>Last sign-in</th><th>Status</th><th /></tr></thead>
            <tbody>
              {rows.map((u) => (
                <tr key={u.id}>
                  <td>{u.name}<div className="small muted">{u.email}</div></td>
                  <td>{roleList(u.roles).map(titleCase).join(', ')}</td>
                  <td>{u.branch_name ?? '—'}</td>
                  <td>{dateTime(u.last_login_at)}</td>
                  <td>
                    <StatusBadge status={u.status} />
                    {u.mfa_enabled === '1' || u.mfa_enabled === true ? <span className="badge badge--info" style={{ marginLeft: 6 }}>2FA</span> : null}
                  </td>
                  <td><button type="button" className="btn btn--small" onClick={() => setEditing(u)}>Edit</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {meta ? <Pager page={meta.page} totalPages={meta.total_pages} onPage={(p) => update({ page: String(p) })} /> : null}

      {editing ? (
        <UserModal
          user={editing === 'new' ? null : editing}
          roles={roles ?? []}
          onClose={() => setEditing(null)}
          onDone={(message) => {
            setEditing(null)
            setNotice(message)
            reload()
          }}
        />
      ) : null}
    </>
  )
}

/** Roles that work inside one branch and therefore need a branch. */
const BRANCH_ROLES = ['branch_manager', 'collection_supervisor', 'collection_agent', 'loan_officer']

function UserModal({ user, roles, onClose, onDone }: { user: StaffUser | null; roles: { role_key: string; name: string }[]; onClose: () => void; onDone: (m: string) => void }) {
  const { user: me } = useAuth()
  const { data: branches } = useFetch<Branch[]>('/branches')
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [phone, setPhone] = useState(user?.phone ?? '')
  const [branchId, setBranchId] = useState(user?.branch_id ?? '')
  const [selected, setSelected] = useState<string[]>(user ? roleList(user.roles) : [])
  const [status, setStatus] = useState(user?.status ?? 'active')
  const [password, setPassword] = useState('')
  const [resetMfa, setResetMfa] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)
  const isSelf = user !== null && Number(user.id) === me?.id

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const common = { name, phone: phone || null, branch_id: branchId ? Number(branchId) : null }
      if (user) {
        await api.patch(`/users/${user.id}`, isSelf ? common : { ...common, roles: selected, status })
        if (resetMfa) await api.post(`/users/${user.id}/reset-mfa`)
        if (password) await api.post(`/users/${user.id}/reset-password`, { password })
        onDone(password ? 'User updated and password reset. Their sessions were signed out.' : 'User updated.')
      } else {
        await api.post('/users', { ...common, email, password, roles: selected })
        onDone('User created.')
      }
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={user ? 'Edit staff' : 'Add staff'} onClose={onClose} wide>
      <form onSubmit={submit} className="stack">
        <ErrorBanner error={bannerError(error)} />
        <div className="grid grid--2 grid--tight">
          <Field label="Name *" error={errors.name}><input value={name} onChange={(e) => setName(e.target.value)} /></Field>
          <Field label="Email *" error={errors.email}><input type="email" value={email} disabled={user !== null} onChange={(e) => setEmail(e.target.value)} /></Field>
          <Field label="Phone" error={errors.phone}><input value={phone} onChange={(e) => setPhone(e.target.value)} /></Field>
          <Field label="Branch" error={errors.branch_id} hint="Required for branch-level roles.">
            <select value={branchId} onChange={(e) => setBranchId(e.target.value)}>
              <option value="">No branch (Admin, Finance, Auditor only)</option>
              {(branches ?? []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </Field>
        </div>
        <Field label="Roles *" error={errors.roles} hint={isSelf ? 'You cannot change your own roles.' : undefined}>
          <div className="row row--wrap" style={{ gap: 14 }}>
            {roles.map((r) => (
              <label key={r.role_key} className="checkbox">
                <input
                  type="checkbox"
                  disabled={isSelf}
                  checked={selected.includes(r.role_key)}
                  onChange={(e) => {
                    const checked = e.target.checked
                    setSelected((s) => (checked ? [...s, r.role_key] : s.filter((x) => x !== r.role_key)))
                    // Field and branch staff always belong to a branch: preselect one so the form can't fail on it.
                    if (checked && BRANCH_ROLES.includes(r.role_key) && !branchId && branches?.length) setBranchId(branches[0].id)
                  }}
                />
                {r.name}
              </label>
            ))}
          </div>
        </Field>
        <div className="grid grid--2 grid--tight">
          <Field label={user ? 'Reset password' : 'Password *'} error={errors.password} hint="At least 8 characters.">
            <input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder={user ? 'Leave blank to keep' : ''} />
          </Field>
          {user && !isSelf ? (
            <Field label="Status" error={errors.status}>
              <select value={status} onChange={(e) => setStatus(e.target.value as 'active' | 'disabled')}>
                <option value="active">Active</option>
                <option value="disabled">Disabled (signs out immediately)</option>
              </select>
            </Field>
          ) : null}
        </div>
        {user && !isSelf && (user.mfa_enabled === '1' || user.mfa_enabled === true) ? (
          <label className="checkbox">
            <input type="checkbox" checked={resetMfa} onChange={(e) => setResetMfa(e.target.checked)} />
            Reset two-factor (lost phone) — they will re-enrol at next sign-in
          </label>
        ) : null}
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={saving}>{user ? 'Save' : 'Create user'}</button>
        </div>
      </form>
    </Modal>
  )
}
