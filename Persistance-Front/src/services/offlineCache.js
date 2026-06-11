// Cache navigateur (localStorage) pour le fonctionnement dégradé quand le back
// est injoignable :
//   - session     : profil de la session courante, restaurée au rechargement,
//   - knownUsers  : utilisateurs s'étant déjà connectés EN LIGNE sur cet appareil.
//                   Sert d'unique critère pour autoriser une connexion hors-ligne.
//                   On n'y stocke PAS de token : le JWT n'existe que quand le back
//                   est up et sera recréé à la prochaine connexion en ligne.
//   - history     : pages d'historique déjà consultées.
//
// localStorage est synchrone et persiste au rafraîchissement. En cas de quota
// dépassé, on échoue silencieusement (cache best-effort).

const SESSION_KEY = 'persistance.session'
const KNOWN_USERS_KEY = 'persistance.knownUsers'
const HISTORY_PREFIX = 'persistance.history'

function read(key) {
  try {
    const raw = localStorage.getItem(key)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function write(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // quota dépassé ou stockage indisponible : on ignore
  }
}

// --- Session courante (restaurée au rechargement, y compris hors-ligne) -----

export function saveSession(user) {
  if (!user) return
  write(SESSION_KEY, { user, savedAt: Date.now() })
}

export function loadSession() {
  return read(SESSION_KEY)
}

export function clearSession() {
  try {
    localStorage.removeItem(SESSION_KEY)
  } catch {
    /* ignore */
  }
}

// --- Utilisateurs déjà connectés en ligne (persistent, sans token) ----------

function normEmail(email) {
  return String(email ?? '').trim().toLowerCase()
}

// cred : empreinte du mot de passe { salt, iterations, hash } (cf. passwordHash.js).
// Si cred n'est pas fourni (rafraîchissement de profil), on conserve l'empreinte
// déjà stockée.
export function rememberKnownUser(user, cred) {
  const email = normEmail(user?.email)
  if (!email) return
  const known = read(KNOWN_USERS_KEY) ?? {}
  const prev = known[email]
  known[email] = {
    user,
    cred: cred !== undefined ? cred : (prev?.cred ?? null),
    savedAt: Date.now(),
  }
  write(KNOWN_USERS_KEY, known)
}

export function getKnownUser(email) {
  const known = read(KNOWN_USERS_KEY) ?? {}
  return known[normEmail(email)] ?? null
}

// --- Historique (par utilisateur et par page) ------------------------------

function historyKey(userId, page) {
  return `${HISTORY_PREFIX}:${userId ?? 'anon'}:${page}`
}

export function saveHistoryPage(userId, page, items) {
  write(historyKey(userId, page), items)
}

export function loadHistoryPage(userId, page) {
  return read(historyKey(userId, page))
}
