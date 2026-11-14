import { useState, type FormEvent } from 'react'
import { api } from '../api'
import { useAuth } from '../auth'
import { dateTime, titleCase } from '../format'
import { useFetch } from '../hooks'
import { ProtectedImage } from './ProtectedImage'
import { compressImage } from './RecordPaymentModal'
import { ErrorBanner, Field, Modal, StatusBadge, bannerError, fieldErrors } from './ui'

interface Doc {
  id: string
  doc_type: string
  doc_number_last4: string | null
  original_name: string
  mime_type: string
  size_bytes: string
  status: 'pending' | 'verified' | 'rejected'
  review_note: string | null
  uploaded_at: string
  uploaded_by_name: string
  reviewed_by_name: string | null
}

/**
 * Phone photos are 3–6 MB, often over a shared host's upload limit (2 MB is common) and slow on mobile data:
 * images are sent as a 1400px JPEG (~300 KB). PDFs go as they are.
 */
async function shrink(file: File, name: string): Promise<File> {
  if (!file.type.startsWith('image/')) return file
  try {
    const blob = await (await fetch(await compressImage(file))).blob()
    return new File([blob], `${name}.jpg`, { type: 'image/jpeg' })
  } catch {
    return file
  }
}

const TYPES = ['aadhaar', 'pan', 'voter_id', 'passport', 'driving_licence', 'photo', 'address_proof', 'income_proof', 'other']

/** The three documents taken with the phone camera when a customer is added. */
const QUICK: { type: string; label: string; camera: 'user' | 'environment' }[] = [
  { type: 'photo', label: 'Customer photo', camera: 'user' },
  { type: 'aadhaar', label: 'Aadhaar card', camera: 'environment' },
  { type: 'pan', label: 'PAN card', camera: 'environment' },
]

/**
 * KYC documents for one customer: upload (JPG/PNG/PDF ≤ 5 MB), view through the authorised endpoint, review.
 * `quickCapture` shows one-tap camera tiles for photo / Aadhaar / PAN (used right after onboarding).
 */
export function Documents({ customerId, quickCapture = false }: { customerId: string; quickCapture?: boolean }) {
  const { can } = useAuth()
  const { data: docs, error, reload } = useFetch<Doc[]>(`/customers/${customerId}/documents`)
  const [uploading, setUploading] = useState(false)
  const [reviewing, setReviewing] = useState<Doc | null>(null)
  const [viewing, setViewing] = useState<Doc | null>(null)
  const [capturing, setCapturing] = useState<string | null>(null)
  const [captureError, setCaptureError] = useState<unknown>(null)
  const has = (type: string) => (docs ?? []).find((d) => d.doc_type === type && d.status !== 'rejected')

  async function capture(type: string, file: File | undefined) {
    if (!file) return
    setCapturing(type)
    setCaptureError(null)
    try {
      const form = new FormData()
      form.append('doc_type', type)
      form.append('file', await shrink(file, type))
      await api.upload(`/customers/${customerId}/documents`, form)
      reload()
    } catch (err) {
      // Show the server's reason (e.g. the host's size limit), not just "fields are invalid".
      const fields = fieldErrors(err)
      setCaptureError(fields.file || fields.doc_type ? new Error(fields.file ?? fields.doc_type) : err)
    } finally {
      setCapturing(null)
    }
  }

  return (
    <section className="card">
      <div className="card__header">
        <h2 className="card__title">KYC documents</h2>
        {can('documents.upload') ? <button type="button" className="btn btn--small" onClick={() => setUploading(true)}>Upload</button> : null}
      </div>
      <ErrorBanner error={error} />
      {quickCapture && can('documents.upload') && docs ? (
        <div className="kyc-quick">
          <p className="small muted">Take these now with the camera — the office verifies them.</p>
          <ErrorBanner error={captureError} />
          <div className="kyc-quick__tiles">
            {QUICK.map((q) => {
              const done = has(q.type)
              return (
                <label key={q.type} className={`kyc-tile${done ? ' kyc-tile--done' : ''}`}>
                  {done && q.type === 'photo' ? <ProtectedImage path={`/documents/${done.id}/download`} alt="Customer photo" className="kyc-tile__photo" /> : <span className="kyc-tile__icon">{done ? '✓' : '📷'}</span>}
                  <span>{capturing === q.type ? 'Uploading…' : done ? `${q.label} ✓` : q.label}</span>
                  <input type="file" accept="image/*" capture={q.camera} hidden disabled={capturing !== null} onChange={(e) => void capture(q.type, e.target.files?.[0])} />
                </label>
              )
            })}
          </div>
        </div>
      ) : null}
      {docs && docs.length === 0 ? <p className="muted">No documents uploaded yet.</p> : null}
      {docs ? (
        <div className="doc-grid">
          {docs.map((d) => (
            <div key={d.id} className="doc">
              <div className="row row--between">
                <strong>{titleCase(d.doc_type)}</strong>
                <StatusBadge status={d.status === 'verified' ? 'verified' : d.status === 'rejected' ? 'rejected' : 'pending'} />
              </div>
              {d.doc_number_last4 ? <div className="small muted mono">•••• {d.doc_number_last4}</div> : null}
              <div className="small muted" style={{ marginTop: 4 }}>{dateTime(d.uploaded_at)} · {d.uploaded_by_name}</div>
              {d.review_note ? <div className="small" style={{ marginTop: 4 }}>“{d.review_note}”</div> : null}
              <div className="row" style={{ marginTop: 10 }}>
                {/* The session cookie authorises this link; every view is audited server-side. */}
                <button type="button" className="btn btn--small" onClick={() => setViewing(d)}>View</button>
                {can('documents.review') && d.status === 'pending' ? <button type="button" className="btn btn--small" onClick={() => setReviewing(d)}>Review</button> : null}
              </div>
            </div>
          ))}
        </div>
      ) : null}
      {viewing ? (
        <Modal title={titleCase(viewing.doc_type)} onClose={() => setViewing(null)} wide>
          {viewing.mime_type === 'application/pdf' ? (
            <button type="button" className="btn" onClick={() => api.download(`/documents/${viewing.id}/download`, viewing.original_name)}>Download PDF</button>
          ) : (
            <ProtectedImage path={`/documents/${viewing.id}/download`} alt={titleCase(viewing.doc_type)} className="proof-image" />
          )}
        </Modal>
      ) : null}
      {uploading ? <UploadModal customerId={customerId} onClose={() => setUploading(false)} onDone={() => { setUploading(false); reload() }} /> : null}
      {reviewing ? <ReviewModal doc={reviewing} onClose={() => setReviewing(null)} onDone={() => { setReviewing(null); reload() }} /> : null}
    </section>
  )
}

function UploadModal({ customerId, onClose, onDone }: { customerId: string; onClose: () => void; onDone: () => void }) {
  const [type, setType] = useState('aadhaar')
  const [last4, setLast4] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [saving, setSaving] = useState(false)
  const errors = fieldErrors(error)

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (!file) return
    const form = new FormData()
    form.append('doc_type', type)
    if (last4) form.append('doc_number_last4', last4)
    setSaving(true)
    setError(null)
    try {
      form.append('file', await shrink(file, type))
      await api.upload(`/customers/${customerId}/documents`, form)
      onDone()
    } catch (err) {
      setError(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title="Upload document" onClose={onClose}>
      <form onSubmit={submit} className="stack">
        <ErrorBanner error={bannerError(error)} />
        <Field label="Document type" error={errors.doc_type}>
          <select value={type} onChange={(e) => setType(e.target.value)}>{TYPES.map((t) => <option key={t} value={t}>{titleCase(t)}</option>)}</select>
        </Field>
        <Field label="Last 4 characters of the number" error={errors.doc_number_last4} hint="We store only the last 4 characters, never the full ID number.">
          <input maxLength={4} value={last4} onChange={(e) => setLast4(e.target.value)} />
        </Field>
        <Field label="File (JPG, PNG or PDF, max 5 MB)" error={errors.file}>
          <input type="file" accept="image/*,application/pdf" capture="environment" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
        <div className="modal__footer">
          <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn btn--primary" disabled={!file || saving}>{saving ? 'Uploading…' : 'Upload'}</button>
        </div>
      </form>
    </Modal>
  )
}

function ReviewModal({ doc, onClose, onDone }: { doc: Doc; onClose: () => void; onDone: () => void }) {
  const [note, setNote] = useState('')
  const [error, setError] = useState<unknown>(null)

  async function decide(status: 'verified' | 'rejected') {
    setError(null)
    try {
      await api.post(`/documents/${doc.id}/review`, { status, note: note || null })
      onDone()
    } catch (err) {
      setError(err)
    }
  }

  return (
    <Modal title={`Review ${titleCase(doc.doc_type)}`} onClose={onClose}>
      <p>Check the document matches the customer before deciding.</p>
      {doc.mime_type === 'application/pdf' ? (
        <button type="button" className="btn btn--small" onClick={() => api.download(`/documents/${doc.id}/download`, doc.original_name)}>Download PDF</button>
      ) : (
        <ProtectedImage path={`/documents/${doc.id}/download`} alt="Document under review" className="proof-image" />
      )}
      <ErrorBanner error={bannerError(error)} />
      <Field label="Note (required to reject)" error={fieldErrors(error).note}><textarea value={note} onChange={(e) => setNote(e.target.value)} /></Field>
      <div className="modal__footer">
        <button type="button" className="btn btn--danger" onClick={() => decide('rejected')}>Reject</button>
        <button type="button" className="btn btn--primary" onClick={() => decide('verified')}>Verify</button>
      </div>
    </Modal>
  )
}
