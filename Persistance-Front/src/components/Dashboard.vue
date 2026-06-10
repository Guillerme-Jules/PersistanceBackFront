<script setup>
import { ref, onMounted } from 'vue'
import { useAuth } from '@/stores/auth'
import { searchApi, SEARCH_TYPES, METRICS } from '@/services/api'

const { state, logout } = useAuth()

const searches = ref([])
const loading = ref(false)
const loadError = ref(null)

// Formulaire de création d'une recherche (démonstration des champs du back).
const form = ref({ type: 'average_metric_on_day', metric: 'bz', from: '2024-06-09', to: '' })
const creating = ref(false)

async function loadHistory() {
  loading.value = true
  loadError.value = null
  try {
    const { items } = await searchApi.history({ limit: 50 })
    searches.value = items
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

async function createSearch() {
  creating.value = true
  try {
    // On n'envoie que les champs renseignés.
    const payload = Object.fromEntries(Object.entries(form.value).filter(([, v]) => v !== '' && v != null))
    const created = await searchApi.create(payload)
    // 202 : la recherche s'exécute en asynchrone côté back ; on l'ajoute en tête.
    searches.value.unshift(created)
  } catch (e) {
    loadError.value = e.message
  } finally {
    creating.value = false
  }
}

async function replay(id) {
  try {
    const r = await searchApi.replay(id)
    searches.value.unshift(r)
  } catch (e) {
    loadError.value = e.message
  }
}

onMounted(loadHistory)
</script>

<template>
  <div class="dashboard">
    <header class="topbar">
      <div>
        <h1>Persistance</h1>
        <p v-if="state.user" class="who">{{ state.user.email }} · {{ state.user.roles.join(', ') }}</p>
      </div>
      <button class="ghost" @click="logout">Se déconnecter</button>
    </header>

    <section class="panel">
      <h2>Nouvelle recherche</h2>
      <div class="row">
        <label class="field">
          <span>Type</span>
          <select v-model="form.type">
            <option v-for="t in SEARCH_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </label>
        <label class="field">
          <span>Métrique</span>
          <select v-model="form.metric">
            <option v-for="m in METRICS" :key="m" :value="m">{{ m }}</option>
          </select>
        </label>
        <label class="field">
          <span>Du</span>
          <input v-model="form.from" type="date" />
        </label>
        <label class="field">
          <span>Au</span>
          <input v-model="form.to" type="date" />
        </label>
        <button class="primary" :disabled="creating" @click="createSearch">
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
          </div>
          <div class="item-meta">
            <span>{{ s.type }}</span>
            <span v-if="s.result">{{ s.result.rowCount }} lignes · {{ s.result.durationMs }} ms</span>
            <span v-if="s.error" class="error">{{ s.error }}</span>
          </div>
          <button class="link" @click="replay(s.id)">Relancer</button>
        </li>
      </ul>
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
  gap: 1rem;
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
.link {
  grid-row: 1 / span 2;
  grid-column: 2;
  align-self: center;
  background: none;
  border: none;
  color: var(--accent);
  cursor: pointer;
  font-size: 0.85rem;
}
.muted {
  color: var(--muted);
}
.error {
  color: #ff9b9b;
}
</style>
