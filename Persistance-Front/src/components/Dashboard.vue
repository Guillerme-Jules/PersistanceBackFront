<script setup>
import { ref, reactive, onMounted, onBeforeUnmount, watch, computed } from 'vue'
import { useAuth } from '@/stores/auth'
import { searchApi, SEARCH_TYPES, METRICS, OPERATORS } from '@/services/api'
import { isNetworkError } from '@/services/http'
import { saveHistoryPage, loadHistoryPage } from '@/services/offlineCache'

const { state, logout, setOffline } = useAuth()

const searches = ref([])
const loading = ref(false)
const loadError = ref(null)
const live = ref(false)

const PAGE_SIZE = 10
const page = ref(0)
const hasNext = ref(false)

const form = ref({
  type: 'average_metric_on_day',
  metric: 'bz',
  from: '2024-06-09',
  to: '',
  operator: '<',
  threshold: '',
  bucketHours: 12,
})
const creating = ref(false)

const fields = computed(() => {
  switch (form.value.type) {
    case 'average_metric_on_day':
      return { metric: true, from: true }
    case 'threshold_crossing':
      return { metric: true, from: true, to: true, operator: true, threshold: true }
    case 'bucket_average':
      return { metric: true, from: true, to: true, bucketHours: true }
    case 'raw_range':
      return { from: true, to: true }
    default:
      return { metric: true, from: true }
  }
})

async function loadHistory() {
  loading.value = true
  loadError.value = null
  const userKey = state.user?.id ?? 'anon'
  try {
    const { items } = await searchApi.history({ limit: PAGE_SIZE, offset: page.value * PAGE_SIZE })
    searches.value = items
    hasNext.value = items.length === PAGE_SIZE
    setOffline(false)
    saveHistoryPage(userKey, page.value, items) // mémorise la page pour l'accès hors-ligne
  } catch (e) {
    if (isNetworkError(e)) {
      // Back injoignable : on sert la page depuis le cache si on l'a déjà consultée.
      setOffline(true)
      const cached = loadHistoryPage(userKey, page.value)
      searches.value = cached ?? []
      hasNext.value = false // on ne connaît pas la suite hors-ligne
      if (!cached) loadError.value = 'Hors-ligne et aucune donnée en cache pour cette page.'
    } else {
      loadError.value = e.message
    }
  } finally {
    loading.value = false
  }
}

function goPage(delta) {
  const next = page.value + delta
  if (next < 0) return
  if (delta > 0 && !hasNext.value) return
  page.value = next
  loadHistory()
}

function mergeSearch(s) {
  const i = searches.value.findIndex((x) => x.id === s.id)
  if (i >= 0) searches.value[i] = s
  else if (page.value === 0) searches.value.unshift(s)
}

async function createSearch() {
  creating.value = true
  loadError.value = null
  try {
    const payload = Object.fromEntries(
      Object.entries(form.value)
        .filter(([k]) => fields.value[k] || k === 'type')
        .filter(([, v]) => v !== '' && v != null),
    )
    if (payload.threshold != null) payload.threshold = Number(payload.threshold)
    if (payload.bucketHours != null) payload.bucketHours = Number(payload.bucketHours)

    if (page.value !== 0) {
      page.value = 0
      await loadHistory()
    }
    const created = await searchApi.create(payload)
    mergeSearch(created)
  } catch (e) {
    loadError.value = e.message
  } finally {
    creating.value = false
  }
}

async function replay(id) {
  loadError.value = null
  try {
    if (page.value !== 0) {
      page.value = 0
      await loadHistory()
    }
    const r = await searchApi.replay(id)
    mergeSearch(r)
  } catch (e) {
    loadError.value = e.message
  }
}

let es = null
function subscribeLive() {
  const m = state.user?.mercure
  if (!m?.hub || !m?.topic || es) return
  const url = new URL(m.hub)
  url.searchParams.append('topic', m.topic)
  es = new EventSource(url.toString())
  es.onopen = () => (live.value = true)
  es.onerror = () => (live.value = false)
  es.onmessage = (event) => {
    try {
      mergeSearch(JSON.parse(event.data))
    } catch {
      void 0
    }
  }
}
function unsubscribeLive() {
  es?.close()
  es = null
  live.value = false
}

onMounted(() => {
  loadHistory()
  subscribeLive()
})
watch(() => state.user, subscribeLive)
onBeforeUnmount(unsubscribeLive)

const SUMMARY_LABELS = {
  metric: 'Métrique',
  day: 'Jour',
  average: 'Moyenne',
  min: 'Min',
  max: 'Max',
  samples: 'Échantillons',
  count: 'Occurrences',
  operator: 'Opérateur',
  threshold: 'Seuil',
  bucketHours: 'Pas (h)',
  from: 'Du',
  to: 'Au',
}

function summaryEntries(summary) {
  if (!summary) return []
  return Object.entries(summary).map(([k, v]) => ({
    key: k,
    label: SUMMARY_LABELS[k] ?? k,
    value: formatValue(v),
  }))
}

function formatValue(v) {
  if (v == null) return '—'
  if (typeof v === 'number') return Number.isInteger(v) ? v.toLocaleString('fr-FR') : v
  return String(v)
}

// Pagination des résultats : on récupère le jeu complet par blocs de 500 lignes
// (un seul aller-retour serveur), affichés 50 par 50 et découpés en mémoire.
// Le bloc suivant est préchargé en arrière-plan pour que « suivant » reste fluide.
const SERVER_BLOCK = 500
const ROWS_PER_PAGE = 50
const expanded = reactive({})
// Par recherche : { columns, blocks: {offset: rows}, total, page, loading, error }
const rowState = reactive({})

function rowTotal(s) {
  return rowState[s.id]?.total ?? s.result?.rowCount ?? 0
}
function rowPagesCount(s) {
  // Hors-ligne : seule la première page (l'aperçu en cache) est disponible.
  if (state.offline) {
    const len = s.result?.preview?.length ?? 0
    return Math.max(1, Math.ceil(len / ROWS_PER_PAGE))
  }
  return Math.max(1, Math.ceil(rowTotal(s) / ROWS_PER_PAGE))
}
function blockOffsetFor(page) {
  return Math.floor((page * ROWS_PER_PAGE) / SERVER_BLOCK) * SERVER_BLOCK
}

async function toggleRows(s) {
  expanded[s.id] = !expanded[s.id]
  if (expanded[s.id] && !rowState[s.id]) {
    rowState[s.id] = {
      columns: s.result?.columns ?? [],
      blocks: {},
      total: s.result?.rowCount ?? 0,
      page: 0,
      loading: false,
      error: null,
    }
    if (state.offline) {
      // Hors-ligne : on affiche directement l'aperçu (première page) sans appel serveur.
      rowState[s.id].blocks = { 0: s.result?.preview ?? [] }
    } else {
      await goToPage(s, 0)
    }
  }
}

async function fetchBlock(s, blockOffset) {
  const { columns, rows, total } = await searchApi.rows(s.id, { limit: SERVER_BLOCK, offset: blockOffset })
  const st = rowState[s.id]
  if (!st) return
  st.blocks = { ...st.blocks, [blockOffset]: rows }
  if (columns) st.columns = columns
  if (total != null) st.total = total // le serveur ne renvoie le total que pour le 1er bloc
}

async function goToPage(s, page) {
  const st = rowState[s.id]
  if (!st) return
  const blockOffset = blockOffsetFor(page)
  if (st.blocks[blockOffset]) {
    st.page = page // bloc déjà en cache → changement instantané
    if (!state.offline) prefetchNext(s, blockOffset)
    return
  }
  // Hors-ligne : on se limite à l'aperçu (bloc 0), les autres pages sont indisponibles.
  if (state.offline) {
    if (blockOffset === 0) {
      st.blocks = { ...st.blocks, 0: s.result?.preview ?? [] }
      st.page = 0
    }
    return
  }
  st.loading = true
  st.error = null
  st.page = page
  try {
    await fetchBlock(s, blockOffset)
    prefetchNext(s, blockOffset)
  } catch (e) {
    if (isNetworkError(e) && s.result?.preview) {
      // Bascule hors-ligne en cours de navigation : on retombe sur l'aperçu en cache.
      setOffline(true)
      st.blocks = { ...st.blocks, 0: s.result.preview }
      st.columns = s.result.columns ?? st.columns
      st.page = 0
    } else {
      st.error = e.message
    }
  } finally {
    st.loading = false
  }
}

// Précharge silencieusement le bloc suivant pour absorber le franchissement de bloc.
function prefetchNext(s, blockOffset) {
  const st = rowState[s.id]
  if (!st) return
  const next = blockOffset + SERVER_BLOCK
  if (next >= (st.total ?? 0) || st.blocks[next]) return
  fetchBlock(s, next).catch(() => {})
}

function rowSlice(s) {
  const st = rowState[s.id]
  if (!st) return []
  const blockOffset = blockOffsetFor(st.page)
  const rows = st.blocks[blockOffset]
  if (!rows) return []
  const start = st.page * ROWS_PER_PAGE - blockOffset
  return rows.slice(start, start + ROWS_PER_PAGE)
}

function goRowPage(s, delta) {
  const st = rowState[s.id]
  if (!st || st.loading) return
  const next = Math.min(Math.max(0, st.page + delta), rowPagesCount(s) - 1)
  if (next === st.page) return
  goToPage(s, next)
}
</script>

<template>
  <div class="dashboard">
    <header class="topbar">
      <div>
        <h1>Persistance</h1>
        <p v-if="state.user" class="who">
          {{ state.user.email }} · {{ state.user.roles.join(', ') }}
          <span class="live" :class="{ on: live }" :title="live ? 'Temps réel actif' : 'Temps réel hors ligne'">
            ● {{ live ? 'live' : 'hors ligne' }}
          </span>
        </p>
      </div>
      <button class="ghost" @click="logout">Se déconnecter</button>
    </header>

    <p v-if="state.offline" class="offline-banner">
      Mode hors-ligne — serveur injoignable. Affichage des données en cache (historique et première page des résultats).
    </p>

    <section class="panel">
      <h2>Nouvelle recherche</h2>
      <div class="row">
        <label class="field">
          <span>Type</span>
          <select v-model="form.type">
            <option v-for="t in SEARCH_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </label>
        <label v-if="fields.metric" class="field">
          <span>Métrique</span>
          <select v-model="form.metric">
            <option v-for="m in METRICS" :key="m" :value="m">{{ m }}</option>
          </select>
        </label>
        <label v-if="fields.from" class="field">
          <span>Du</span>
          <input v-model="form.from" type="date" />
        </label>
        <label v-if="fields.to" class="field">
          <span>Au</span>
          <input v-model="form.to" type="date" />
        </label>
        <label v-if="fields.operator" class="field">
          <span>Opérateur</span>
          <select v-model="form.operator">
            <option v-for="o in OPERATORS" :key="o" :value="o">{{ o }}</option>
          </select>
        </label>
        <label v-if="fields.threshold" class="field">
          <span>Seuil</span>
          <input v-model="form.threshold" type="number" step="any" placeholder="ex: -40" />
        </label>
        <label v-if="fields.bucketHours" class="field">
          <span>Pas (h)</span>
          <input v-model="form.bucketHours" type="number" min="1" />
        </label>
        <button class="primary" :disabled="creating || state.offline" @click="createSearch">
          {{ creating ? 'Envoi…' : 'Lancer' }}
        </button>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Historique</h2>
        <button class="ghost" @click="loadHistory" :disabled="loading">Rafraîchir</button>
      </div>

      <p v-if="loadError" class="error">{{ loadError }}</p>
      <p v-if="loading" class="muted">Chargement…</p>
      <p v-else-if="!searches.length" class="muted">Aucune recherche pour l'instant.</p>

      <ul v-else class="list">
        <li v-for="s in searches" :key="s.id" class="item">
          <div class="item-main">
            <span class="label">{{ s.label }}</span>
            <span class="badge" :data-status="s.status">{{ s.status }}</span>
            <span v-if="s.status === 'pending' || s.status === 'running'" class="spinner" />
          </div>

          <div class="item-meta">
            <span>{{ s.type }}</span>
            <span v-if="s.result">· {{ s.result.durationMs }} ms</span>
            <span v-if="s.result?.rowCount">· {{ s.result.rowCount.toLocaleString('fr-FR') }} lignes</span>
            <span v-if="s.error" class="error">{{ s.error }}</span>
          </div>

          <div v-if="s.result" class="result">
            <div v-if="summaryEntries(s.result.summary).length" class="summary">
              <div v-for="e in summaryEntries(s.result.summary)" :key="e.key" class="stat">
                <span class="stat-label">{{ e.label }}</span>
                <span class="stat-value">{{ e.value }}</span>
              </div>
            </div>

            <template v-if="s.result.preview && s.result.preview.length">
              <button class="link small" @click="toggleRows(s)">
                {{ expanded[s.id] ? 'Masquer' : 'Voir' }} les données ({{ s.result.rowCount.toLocaleString('fr-FR') }})
              </button>
              <div v-if="expanded[s.id]" class="table-wrap">
                <p v-if="rowState[s.id]?.error" class="muted">{{ rowState[s.id].error }}</p>
                <p v-else-if="rowState[s.id]?.loading && rowSlice(s).length === 0" class="muted">Chargement…</p>
                <table v-else-if="rowSlice(s).length" class="data">
                  <thead>
                    <tr>
                      <th v-for="c in (rowState[s.id]?.columns?.length ? rowState[s.id].columns : s.result.columns)" :key="c">{{ c }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(r, i) in rowSlice(s)" :key="i">
                      <td v-for="c in (rowState[s.id]?.columns?.length ? rowState[s.id].columns : s.result.columns)" :key="c">{{ formatValue(r[c]) }}</td>
                    </tr>
                  </tbody>
                </table>
                <div v-if="rowPagesCount(s) > 1" class="pager small">
                  <button class="ghost" :disabled="(rowState[s.id]?.page ?? 0) === 0 || rowState[s.id]?.loading" @click="goRowPage(s, -1)">‹</button>
                  <span class="muted">{{ (rowState[s.id]?.page ?? 0) + 1 }} / {{ rowPagesCount(s) }}<span v-if="rowState[s.id]?.loading"> …</span></span>
                  <button class="ghost" :disabled="(rowState[s.id]?.page ?? 0) >= rowPagesCount(s) - 1 || rowState[s.id]?.loading" @click="goRowPage(s, 1)">›</button>
                </div>
                <p class="muted">
                  {{ rowTotal(s).toLocaleString('fr-FR') }} lignes au total.<span
                    v-if="state.offline && rowTotal(s) > (s.result?.preview?.length ?? 0)"
                  > Hors-ligne : seuls les {{ (s.result?.preview?.length ?? 0) }} premiers sont consultables.</span>
                </p>
              </div>
            </template>
          </div>

          <button class="link" :disabled="state.offline" @click="replay(s.id)">Relancer</button>
        </li>
      </ul>

      <div class="pager">
        <button class="ghost" :disabled="page === 0 || loading" @click="goPage(-1)">‹ Précédent</button>
        <span class="muted">Page {{ page + 1 }}</span>
        <button class="ghost" :disabled="!hasNext || loading" @click="goPage(1)">Suivant ›</button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.dashboard {
  width: 100%;
  max-width: 820px;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.topbar h1 {
  margin: 0;
  font-size: 1.4rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--accent);
}
.who {
  margin: 0.2rem 0 0;
  color: var(--muted);
  font-size: 0.85rem;
}
.live {
  margin-left: 0.6rem;
  font-size: 0.72rem;
  color: #ff9b9b;
}
.live.on {
  color: var(--accent);
}
.panel {
  border: 1px solid var(--line);
  border-radius: 14px;
  background: var(--surface);
  padding: 1.5rem;
}
.panel h2 {
  margin: 0 0 1rem;
  font-size: 1rem;
  color: var(--text);
}
.panel-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: flex-end;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.8rem;
  color: var(--muted);
}
.field select,
.field input {
  padding: 0.5rem 0.6rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--bg);
  color: var(--text);
}
.primary {
  padding: 0.55rem 1.1rem;
  border: none;
  border-radius: 8px;
  background: var(--accent);
  color: #06121f;
  font-weight: 600;
  cursor: pointer;
}
.ghost {
  padding: 0.45rem 0.9rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: transparent;
  color: var(--text);
  cursor: pointer;
}
.ghost:disabled {
  opacity: 0.4;
  cursor: default;
}
.list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
}
.item {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0.3rem 1rem;
  padding: 0.85rem 0;
  border-top: 1px solid var(--line);
}
.item:first-child {
  border-top: none;
}
.item-main {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
.label {
  font-weight: 600;
}
.item-meta {
  grid-column: 1;
  display: flex;
  gap: 0.5rem;
  color: var(--muted);
  font-size: 0.82rem;
}
.badge {
  font-size: 0.72rem;
  padding: 0.12rem 0.5rem;
  border-radius: 999px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  background: rgba(255, 255, 255, 0.08);
  color: var(--muted);
}
.badge[data-status='done'] {
  color: #06121f;
  background: var(--accent);
}
.badge[data-status='failed'] {
  color: #06121f;
  background: #ff9b9b;
}
.spinner {
  width: 12px;
  height: 12px;
  border: 2px solid var(--line);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
.result {
  grid-column: 1;
  margin-top: 0.4rem;
}
.summary {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.stat {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  padding: 0.4rem 0.7rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--bg);
}
.stat-label {
  font-size: 0.68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.stat-value {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.table-wrap {
  margin-top: 0.5rem;
  overflow-x: auto;
}
table.data {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}
table.data th,
table.data td {
  text-align: left;
  padding: 0.35rem 0.6rem;
  border-bottom: 1px solid var(--line);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
table.data th {
  color: var(--muted);
  font-weight: 600;
}
.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.8rem;
  margin-top: 1rem;
}
.pager.small {
  justify-content: flex-start;
  margin-top: 0.5rem;
}
.link {
  grid-row: 1 / span 3;
  grid-column: 2;
  align-self: start;
  background: none;
  border: none;
  color: var(--accent);
  cursor: pointer;
  font-size: 0.85rem;
}
.link.small {
  grid-row: auto;
  grid-column: auto;
  padding: 0;
  margin-top: 0.4rem;
  font-size: 0.8rem;
}
.muted {
  color: var(--muted);
}
.error {
  color: #ff9b9b;
}
.offline-banner {
  margin: 0;
  padding: 0.7rem 1rem;
  border: 1px solid #b8862b;
  border-radius: 10px;
  background: rgba(184, 134, 43, 0.12);
  color: #e7c478;
  font-size: 0.9rem;
}
</style>
