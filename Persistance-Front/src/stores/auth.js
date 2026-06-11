// Store d'authentification partagé, sans Pinia : un état réactif unique (module
// singleton) exposé via le composable useAuth().
//
// Modèle hors-ligne :
//  - En ligne, une connexion réussie mémorise l'utilisateur (profil) dans le
//    navigateur via rememberKnownUser().
//  - Si le back est down, on peut se connecter UNIQUEMENT si l'email correspond
//    à un utilisateur déjà connu (déjà connecté en ligne ici). Cette session
//    hors-ligne n'a PAS de token JWT.
//  - Le JWT est (re)créé uniquement par une vraie connexion en ligne, dès que le
//    back est de nouveau joignable.

import { reactive, computed, readonly } from 'vue'
import { authApi, meApi } from '@/services/api'
import { getToken, setToken, clearToken, isTokenExpired } from '@/services/token'
import { isNetworkError } from '@/services/http'
import { saveSession, loadSession, clearSession, rememberKnownUser, getKnownUser } from '@/services/offlineCache'
import { hashPassword, verifyPassword } from '@/services/passwordHash'

const state = reactive({
  token: getToken(),
  user: null, // { id, email, roles, mercure } chargé depuis /api/me ou restauré du cache
  loading: false,
  error: null,
  offline: false, // true en mode dégradé (back injoignable)
})

// 401 = token rejeté/absent côté serveur : on nettoie la session active.
// (Les utilisateurs connus restent mémorisés pour une future connexion.)
window.addEventListener('auth:unauthorized', () => {
  state.token = null
  state.user = null
  state.offline = false
  clearSession()
})

async function login(email, password) {
  state.loading = true
  state.error = null
  try {
    const { token } = await authApi.login(email, password)
    setToken(token)
    state.token = token
    state.offline = false
    await fetchMe()
    // Mémorise une empreinte du mot de passe pour pouvoir le vérifier hors-ligne.
    try {
      const cred = await hashPassword(password)
      rememberKnownUser(state.user, cred)
    } catch {
      /* Web Crypto indisponible : la vérif hors-ligne sera simplement impossible */
    }
    return true
  } catch (e) {
    if (isNetworkError(e)) {
      // Back down : connexion hors-ligne autorisée uniquement si cet email s'est
      // déjà connecté en ligne ici ET si le mot de passe correspond à l'empreinte
      // stockée. Aucune création de token.
      const known = getKnownUser(email)
      if (!known?.user) {
        state.error = "Connexion hors-ligne impossible : ce compte ne s'est jamais connecté en ligne sur cet appareil."
        return false
      }
      if (!known.cred) {
        state.error = 'Mot de passe non vérifiable hors-ligne : reconnectez-vous une fois en ligne pour activer cette possibilité.'
        return false
      }
      const ok = await verifyPassword(password, known.cred)
      if (!ok) {
        state.error = 'Mot de passe incorrect.'
        return false
      }
      clearToken()
      state.token = null
      state.user = known.user
      state.offline = true
      saveSession(known.user) // pour rester connecté au rechargement (hors-ligne)
      return true
    }
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
    return await login(email, password)
  } catch (e) {
    state.error = isNetworkError(e) ? 'Hors-ligne : inscription impossible.' : e.message
    return false
  } finally {
    state.loading = false
  }
}

async function fetchMe() {
  if (!state.token) return null
  state.user = await meApi.get()
  state.offline = false
  saveSession(state.user) // session courante (restaurée au rechargement)
  rememberKnownUser(state.user) // mémorise l'utilisateur pour une connexion hors-ligne ultérieure
  return state.user
}

function logout() {
  clearToken()
  clearSession()
  state.token = null
  state.user = null
  state.offline = false
  state.error = null
}

// Restaure une session dégradée (sans token) depuis le cache. Renvoie true si OK.
function restoreOfflineSession() {
  const cached = loadSession()
  if (cached?.user) {
    state.user = cached.user
    state.offline = true
    return true
  }
  return false
}

async function initialize() {
  // Token valide : on tente le back. S'il est down, on bascule en session dégradée.
  if (state.token && !isTokenExpired(state.token)) {
    try {
      await fetchMe()
    } catch (e) {
      if (isNetworkError(e)) restoreOfflineSession()
      else logout()
    }
    return
  }

  // Pas de token valide : on restaure une session dégradée si l'utilisateur s'est
  // déjà connecté ici. Pas de token (il sera recréé via une connexion en ligne).
  if (restoreOfflineSession()) {
    clearToken()
    state.token = null
  } else {
    logout()
  }
}

function setOffline(value) {
  state.offline = !!value
}

// Authentifié si le token est valide, OU si on dispose d'une session dégradée
// (mode hors-ligne) restaurée du cache. Quand le back revient, le premier appel
// sans token renvoie 401 → auth:unauthorized → retour à l'écran de connexion,
// où une vraie connexion recrée le JWT.
const isAuthenticated = computed(
  () => (!!state.token && !isTokenExpired(state.token)) || (state.offline && !!state.user),
)

export function useAuth() {
  return {
    state: readonly(state),
    isAuthenticated,
    login,
    register,
    logout,
    fetchMe,
    initialize,
    setOffline,
  }
}
