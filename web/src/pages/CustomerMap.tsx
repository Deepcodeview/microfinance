import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Empty, ErrorBanner, Loading, PageHeader, Segmented } from '../components/ui'
import { getLocation, type GeoReading } from '../field'
import { money } from '../format'
import { useFetch } from '../hooks'

type Status = 'overdue' | 'due' | 'collected' | 'none'

interface MapCustomer {
  id: string
  full_name: string
  customer_number: string
  phone: string
  address_line: string
  city: string
  latitude: string
  longitude: string
  due_amount: string
  collected_today: string
  savings_due: number
  status: Status
}

interface MapData {
  business_date: string
  customers: MapCustomer[]
  unlocated: { id: string; full_name: string; customer_number: string; city: string; status: Status }[]
}

/** Pin colours read as meaning: red = overdue, amber = due today, green = collected, grey = nothing due. */
const COLOUR: Record<Status, string> = { overdue: '#c0392b', due: '#d68910', collected: '#2e7d4f', none: '#8a8780' }
const LABEL: Record<Status, string> = { overdue: 'Overdue', due: 'Due today', collected: 'Collected', none: 'Nothing due' }

/** Straight-line distance in km (haversine). */
function km(a: { latitude: number; longitude: number }, lat: number, lng: number): number {
  const rad = (d: number) => (d * Math.PI) / 180
  const dLat = rad(lat - a.latitude), dLng = rad(lng - a.longitude)
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(lat)) * Math.sin(dLng / 2) ** 2
  return 6371 * 2 * Math.asin(Math.sqrt(h))
}

const directions = (lat: string, lng: string) => `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`

/** Agents' map of their customers: where each one is, who to collect from, nearest first, with directions. */
export function CustomerMap() {
  const { data, error } = useFetch<MapData>('/collections/map')
  const [me, setMe] = useState<GeoReading | null>(null)
  const [filter, setFilter] = useState<'todo' | 'all' | 'collected'>('todo')
  const [selected, setSelected] = useState<string | null>(null)
  const mapEl = useRef<HTMLDivElement>(null)
  const map = useRef<L.Map | null>(null)
  const layer = useRef<L.LayerGroup | null>(null)

  useEffect(() => {
    void getLocation().then(setMe)
  }, [])

  const shown = useMemo(() => {
    const list = (data?.customers ?? []).filter((c) =>
      filter === 'all' ? true : filter === 'collected' ? c.status === 'collected' : c.status === 'overdue' || c.status === 'due')
    const withDistance = list.map((c) => ({ ...c, km: me ? km(me, Number(c.latitude), Number(c.longitude)) : null }))
    return withDistance.sort((a, b) => (a.km ?? 0) - (b.km ?? 0) || a.full_name.localeCompare(b.full_name))
  }, [data, filter, me])

  // The Leaflet map is created once; its markers are redrawn when the list or the selection changes.
  useEffect(() => {
    if (!mapEl.current || map.current) return
    map.current = L.map(mapEl.current, { zoomControl: true, attributionControl: true }).setView([25.6, 85.14], 12)
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map.current)
    layer.current = L.layerGroup().addTo(map.current)
    return () => {
      map.current?.remove()
      map.current = null
    }
  }, [data])

  useEffect(() => {
    if (!map.current || !layer.current) return
    layer.current.clearLayers()
    const points: L.LatLngExpression[] = []
    for (const c of shown) {
      const at: L.LatLngTuple = [Number(c.latitude), Number(c.longitude)]
      points.push(at)
      L.circleMarker(at, {
        radius: c.id === selected ? 11 : 8,
        color: '#ffffff',
        weight: 2,
        fillColor: COLOUR[c.status],
        fillOpacity: 0.95,
      })
        .addTo(layer.current)
        .on('click', () => setSelected(c.id))
    }
    if (me) {
      const at: L.LatLngTuple = [me.latitude, me.longitude]
      points.push(at)
      L.circleMarker(at, { radius: 7, color: '#ffffff', weight: 3, fillColor: '#1f6feb', fillOpacity: 1 }).addTo(layer.current)
    }
    if (points.length > 0 && !selected) map.current.fitBounds(L.latLngBounds(points), { padding: [28, 28], maxZoom: 16 })
  }, [shown, me, selected])

  useEffect(() => {
    const c = shown.find((x) => x.id === selected)
    if (c && map.current) map.current.setView([Number(c.latitude), Number(c.longitude)], Math.max(map.current.getZoom(), 15))
  }, [selected, shown])

  if (!data) return error ? <ErrorBanner error={error} /> : <Loading />
  const pick = shown.find((c) => c.id === selected) ?? null
  const counts = (s: Status) => data.customers.filter((c) => c.status === s).length

  return (
    <>
      <PageHeader title="Customer map" subtitle={`${counts('overdue')} overdue · ${counts('due')} due · ${counts('collected')} collected today${me ? ' · nearest first' : ''}`} />
      <div className="toolbar">
        <Segmented<'todo' | 'all' | 'collected'>
          options={[{ id: 'todo', label: 'To collect' }, { id: 'collected', label: 'Collected' }, { id: 'all', label: 'All' }]}
          value={filter}
          onChange={(f) => { setFilter(f); setSelected(null) }}
        />
      </div>
      <div className="map-box" ref={mapEl} />
      <div className="map-legend small">
        {(['overdue', 'due', 'collected', 'none'] as Status[]).map((s) => <span key={s}><i style={{ background: COLOUR[s] }} />{LABEL[s]}</span>)}
        {me ? <span><i style={{ background: '#1f6feb' }} />You</span> : <span className="muted">Turn on GPS to sort by distance</span>}
      </div>

      {pick ? <MapCard c={pick} onClose={() => setSelected(null)} /> : null}

      {shown.length === 0 ? (
        <div className="card"><Empty title={filter === 'collected' ? 'Nothing collected yet today' : 'No one to collect from'}>Customers appear here once their location is set.</Empty></div>
      ) : (
        <div className="map-list">
          {shown.map((c) => (
            <button key={c.id} type="button" className={`map-row${c.id === selected ? ' is-selected' : ''}`} onClick={() => { setSelected(c.id); mapEl.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }}>
              <i className="map-dot" style={{ background: COLOUR[c.status] }} />
              <span className="map-row__who">
                <strong>{c.full_name}</strong>
                <span className="small muted">{c.address_line}, {c.city}</span>
              </span>
              <span className="map-row__right">
                {Number(c.due_amount) > 0 ? <strong>{money(c.due_amount)}</strong> : <span className="small muted">{LABEL[c.status]}</span>}
                {c.km !== null ? <span className="small muted">{c.km < 1 ? `${Math.round(c.km * 1000)} m` : `${c.km.toFixed(1)} km`}</span> : null}
              </span>
            </button>
          ))}
        </div>
      )}

      {data.unlocated.length > 0 ? (
        <details className="card" style={{ marginTop: 14 }}>
          <summary><strong>{data.unlocated.length} customers have no location yet</strong></summary>
          <p className="small muted">It is set automatically the first time you collect there with GPS on, or open the customer and tap <b>Set location here</b>.</p>
          {data.unlocated.map((u) => (
            <Link key={u.id} to={`/customers/${u.id}`} className="map-unlocated">{u.full_name} <span className="small muted">{u.customer_number} · {u.city}</span></Link>
          ))}
        </details>
      ) : null}
    </>
  )
}

function MapCard({ c, onClose }: { c: MapCustomer & { km: number | null }; onClose: () => void }) {
  return (
    <section className="card map-card">
      <div className="row row--between">
        <strong>{c.full_name}</strong>
        <button type="button" className="btn btn--ghost btn--small" onClick={onClose} aria-label="Close">×</button>
      </div>
      <div className="small muted">{c.customer_number} · {c.address_line}, {c.city}</div>
      <div className="small" style={{ marginTop: 6 }}>
        <span style={{ color: COLOUR[c.status], fontWeight: 600 }}>{LABEL[c.status]}</span>
        {Number(c.due_amount) > 0 ? ` · loan due ${money(c.due_amount)}` : ''}
        {c.savings_due > 0 ? ` · savings due` : ''}
        {Number(c.collected_today) > 0 ? ` · collected ${money(c.collected_today)} today` : ''}
      </div>
      <div className="row row--wrap" style={{ gap: 8, marginTop: 10 }}>
        <a className="btn btn--primary btn--small" href={directions(c.latitude, c.longitude)} target="_blank" rel="noreferrer">Directions</a>
        <a className="btn btn--small" href={`tel:${c.phone}`}>Call</a>
        <Link className="btn btn--small" to={`/customers/${c.id}`}>Open customer</Link>
      </div>
    </section>
  )
}
