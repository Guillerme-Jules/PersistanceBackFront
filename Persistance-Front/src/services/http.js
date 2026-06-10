// Client HTTP minimal au-dessus de fetch.
// - injecte automatiquement l'en-tête Authorization: Bearer <jwt>
// - sérialise/désérialise le JSON
// - normalise les erreurs (ApiError) avec le status et le corps renvoyé par le back
// - sur un 401 (token absent/expiré/refusé), purge le token et émet un événement
//   global "auth:unauthorized" que le store d'auth écoute pour déconnecter.

import { getToken, clearToken } from './token'

const BASE_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000').replace(/\/$/, '')

export class ApiError extends Error {
  constructor(message, status, data) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.data = data
  }
}

function buildUrl(path, query) {
  const url = new URL(`${BASE_URL}${path}`)
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null) {
        url.searchParams.set(key, String(value))
      }
    }
  }
  return url.toString()
}

/**
 * @param {string} path        ex: '/api/me'
 * @param {object} [options]
 * @param {string} [options.method='GET']
 * @param {any}    [options.body]          objet JS, sérialisé en JSON
 * @param {object} [options.query]         paramètres de query string
 * @param {boolean}[options.auth=true]     attache le JWT si présent
 * @param {object} [options.headers]
 */
export async function request(path, options = {}) {
  const { method = 'GET', body, query, auth = true, headers = {} } = options

  const finalHeaders = { Accept: 'application/json', ...headers }

  if (auth) {
    const token = getToken()
    if (token) finalHeaders.Authorization = `Bearer ${token}`
  }

  let payload
  if (body !== undefined) {
    finalHeaders['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  let response
  try {
    response = await fetch(buildUrl(path, query), { method, headers: finalHeaders, body: payload })
  } catch (networkError) {
    // Pas de réponse du tout : back éteint, CORS bloqué, hors-ligne…
    throw new ApiError('Impossible de joindre le serveur.', 0, { cause: String(networkError) })
  }

  // 204 No Content ou corps vide
  const text = await response.text()
  const data = text ? safeJson(text) : null

  if (!response.ok) {
    if (response.status === 401) {
      clearToken()
      window.dispatchEvent(new CustomEvent('auth:unauthorized'))
    }
    const message = (data && (data.error || data.message)) || `Erreur ${response.status}`
    throw new ApiError(message, response.status, data)
  }

  return data
}

function safeJson(text) {
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

export const http = {
  get: (path, opts) => request(path, { ...opts, method: 'GET' }),
  post: (path, body, opts) => request(path, { ...opts, method: 'POST', body }),
  put: (path, body, opts) => request(path, { ...opts, method: 'PUT', body }),
  patch: (path, body, opts) => request(path, { ...opts, method: 'PATCH', body }),
  delete: (path, opts) => request(path, { ...opts, method: 'DELETE' }),
}
