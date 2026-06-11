// Hachage de mot de passe côté navigateur, pour vérifier le mot de passe HORS-LIGNE
// (quand le back ne peut pas le faire). On ne stocke JAMAIS le mot de passe en clair :
// uniquement une empreinte PBKDF2-SHA256 avec sel aléatoire et nombreuses itérations.
//
// Repose sur l'API Web Crypto (crypto.subtle), disponible uniquement en contexte
// sécurisé : https, ou http://localhost (votre cas en dev). Hors contexte sécurisé,
// le hachage est indisponible → la vérification hors-ligne sera simplement refusée.

const ITERATIONS = 310000 // coût volontaire pour ralentir une attaque par force brute

function subtle() {
  return globalThis.crypto?.subtle ?? null
}

function bytesToB64(bytes) {
  let s = ''
  bytes.forEach((b) => (s += String.fromCharCode(b)))
  return btoa(s)
}

function b64ToBytes(str) {
  const bin = atob(str)
  const arr = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i)
  return arr
}

async function pbkdf2(password, salt, iterations) {
  const enc = new TextEncoder()
  const keyMaterial = await subtle().importKey('raw', enc.encode(password), 'PBKDF2', false, ['deriveBits'])
  const bits = await subtle().deriveBits(
    { name: 'PBKDF2', salt, iterations, hash: 'SHA-256' },
    keyMaterial,
    256,
  )
  return new Uint8Array(bits)
}

// Comparaison à temps constant (évite de fuiter de l'information par la durée).
function timingSafeEqual(a, b) {
  if (a.length !== b.length) return false
  let diff = 0
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i)
  return diff === 0
}

// Produit { salt, iterations, hash } (base64) à stocker. Renvoie null si Web Crypto
// est indisponible (contexte non sécurisé).
export async function hashPassword(password) {
  if (!subtle()) return null
  const salt = globalThis.crypto.getRandomValues(new Uint8Array(16))
  const hash = await pbkdf2(password, salt, ITERATIONS)
  return { salt: bytesToB64(salt), iterations: ITERATIONS, hash: bytesToB64(hash) }
}

// Vérifie un mot de passe contre une empreinte stockée. false si données absentes
// ou Web Crypto indisponible.
export async function verifyPassword(password, stored) {
  if (!subtle() || !stored?.salt || !stored?.hash || !stored?.iterations) return false
  const salt = b64ToBytes(stored.salt)
  const hash = await pbkdf2(password, salt, stored.iterations)
  return timingSafeEqual(bytesToB64(hash), stored.hash)
}
