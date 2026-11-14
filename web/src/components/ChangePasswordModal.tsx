import { useState, type FormEvent } from 'react'
import { api } from '../api'
import { ErrorBanner, Field, Modal, Notice, bannerError, fieldErrors } from './ui'

export function ChangePasswordModal({ onClose }: { onClose: () => void }) {
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [done, setDone] = useState(false)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await api.post('/me/password', { current_password: current, new_password: next })
      setDone(true)
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title="Change password" onClose={onClose}>
      {done ? (
        <>
          <Notice>Password changed. Your other sessions have been signed out.</Notice>
          <div className="modal__footer">
            <button type="button" className="btn btn--primary" onClick={onClose}>
              Done
            </button>
          </div>
        </>
      ) : (
        <form onSubmit={submit} className="stack">
          <ErrorBanner error={bannerError(error)} />
          <Field label="Current password" error={errors.current_password}>
            <input type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} />
          </Field>
          <Field label="New password" error={errors.new_password} hint="At least 8 characters.">
            <input type="password" autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} />
          </Field>
          <div className="modal__footer">
            <button type="button" className="btn btn--ghost" onClick={onClose}>
              Cancel
            </button>
            <button type="submit" className="btn btn--primary" disabled={saving}>
              {saving ? 'Saving…' : 'Change password'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}
