// Une fonction par endpoint du back Symfony. C'est la seule couche qui connaît
// les chemins d'URL : le reste de l'app appelle ces fonctions.

import { http } from './http'

export const authApi = {
  // POST /api/register  { email, password } -> 201 { id, email }
  register: (email, password) => http.post('/api/register', { email, password }, { auth: false }),

  // POST /api/login  { email, password } -> { token }
  // Géré par le firewall json_login de Symfony, pas besoin de JWT préalable.
  login: (email, password) => http.post('/api/login', { email, password }, { auth: false }),
}

export const meApi = {
  // GET /api/me -> { id, email, roles, mercure: { hub, topic } }
  get: () => http.get('/api/me'),
}

export const searchApi = {
  // GET /api/search?limit&offset -> { items, limit, offset }
  history: ({ limit = 50, offset = 0 } = {}) => http.get('/api/search', { query: { limit, offset } }),

  // POST /api/search { type, metric?, from?, to?, operator?, threshold?, bucketHours?, id?, createdOffline?, label? }
  // -> 202 recherche normalisée
  create: (criteria) => http.post('/api/search', criteria),

  // GET /api/search/{id} -> recherche normalisée
  get: (id) => http.get(`/api/search/${id}`),

  // POST /api/search/{id}/replay -> 202 nouvelle recherche
  replay: (id, body = {}) => http.post(`/api/search/${id}/replay`, body),
}

export const eventsApi = {
  // POST /api/events { events: [{ action, message?, context?, at? }] } -> 202 { accepted }
  send: (events) => http.post('/api/events', { events: Array.isArray(events) ? events : [events] }),
}

// Valeurs autorisées par le back (enums PHP), pratiques pour des <select> côté front.
export const SEARCH_TYPES = ['average_metric_on_day', 'threshold_crossing', 'bucket_average', 'raw_range']
export const METRICS = ['speed', 'density', 'bt', 'bz']
export const OPERATORS = ['<', '<=', '>', '>=', '=']
export const SEARCH_STATUSES = ['pending', 'running', 'done', 'failed']
