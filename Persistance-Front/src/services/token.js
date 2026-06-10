// Stockage et lecture du token JWT.
//
// Choix : localStorage (simple, survit au rafraîchissement, requis pour un usage
// offline/PWA). Inconvénient : un token en localStorage est lisible par du JS,
// donc exposé en cas de faille XSS. Pour un contexte plus sensible, l'alternative
// est un cookie httpOnly posé par le serveur — mais cela demanderait de modifier
// le back (le front ne peut pas créer un cookie httpOnly lui-même).

const TOKEN_KEY = 'persistance.jwt'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_KEY)
  }
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY)
}

// Décode la partie payload d'un JWT (base64url) sans vérifier la signature.
// Utile uniquement côté client pour savoir si le token est expiré avant de
// faire un appel — la vraie validation reste faite par le back.
export function decodeToken(token = getToken()) {
  if (!token) return null
  try {
    const payload = token.split('.')[1]
    const json = atob(payload.replace(/-/g, '+').replace(/_/g, '/'))
    return JSON.parse(decodeURIComponent(escape(json)))
  } catch {
    return null
  }
}

// true si le token est absent ou que sa date d'expiration (exp, en secondes) est passée.
export function isTokenExpired(token = getToken()) {
  const payload = decodeToken(token)
  if (!payload || typeof payload.exp !== 'number') return true
  return payload.exp * 1000 <= Date.now()
}
