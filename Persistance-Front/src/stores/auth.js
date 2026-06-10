// Store d'authentification partagé, sans Pinia : un état réactif unique (module
// singleton) exposé via le composable useAuth(). Tous les composants qui appellent
// useAuth() partagent le même state.

import { reactive, computed, readonly } from 'vue'
import { authApi, meApi } from '@/services/api'
import { getToken, setToken, clearToken, isTokenExpired } from '@/services/token'

const state = reactive({
  token: getToken(),
  user: null, // { id, email, roles, mercure } une fois /api/me chargé
  loading: false,
  error: null,
})

// Si un appel renvoie 401, http.js émet cet événement : on nettoie l'état.
window.addEventListener('auth:unauthorized', () => {
  state.token = null
  state.user = null
})

async function login(email, password) {
  state.loading = true
  state.error = null
  try {
    const { token } = await authApi.login(email, password)
    setToken(token)
    state.token = token
    await fetchMe()
    return true
  } catch (e) {
    state.error = e.message
    return false
  } finally {
    state.loading = false
  }
}

async function register(email, password) {
  state.loading = true
  state.error = null
  try {
    await authApi.register(email, password)
    // Le back ne renvoie pas de token à l'inscription : on enchaîne sur un login.
    return await login(email, password)
  } catch (e) {
    state.error = e.message
    return false
  } finally {
    state.loading = false
  }
}

async function fetchMe() {
  if (!state.token) return null
  state.user = await meApi.get()
  return state.user
}

function logout() {
  clearToken()
  state.token = null
  state.user = null
  state.error = null
}

// Au démarrage de l'app : si on a un token valide mais pas encore l'utilisateur,
// on le récupère. Si le token est expiré, on déconnecte proprement.
async function initialize() {
  if (!state.token) return
  if (isTokenExpired(state.token)) {
    logout()
    return
  }
  try {
    await fetchMe()
  } catch {
    logout()
  }
}

const isAuthenticated = computed(() => !!state.token && !isTokenExpired(state.token))

export function useAuth() {
  return {
    state: readonly(state),
    isAuthenticated,
    login,
    register,
    logout,
    fetchMe,
    initialize,
  }
}
