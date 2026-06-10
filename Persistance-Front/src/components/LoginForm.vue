<script setup>
import { ref } from 'vue'
import { useAuth } from '@/stores/auth'

const { state, login, register } = useAuth()

const mode = ref('login') // 'login' | 'register'
const email = ref('')
const password = ref('')

async function submit() {
  if (mode.value === 'login') {
    await login(email.value, password.value)
  } else {
    await register(email.value, password.value)
  }
}
</script>

<template>
  <div class="card">
    <h1 class="title">Persistance</h1>
    <p class="subtitle">
      {{ mode === 'login' ? 'Connexion à votre espace' : 'Création de compte' }}
    </p>

    <form @submit.prevent="submit" class="form">
      <label class="field">
        <span>Email</span>
        <input v-model="email" type="email" autocomplete="email" required placeholder="vous@example.com" />
      </label>

      <label class="field">
        <span>Mot de passe</span>
        <input
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
          minlength="6"
          placeholder="••••••••"
        />
      </label>

      <p v-if="state.error" class="error">{{ state.error }}</p>

      <button type="submit" class="submit" :disabled="state.loading">
        {{ state.loading ? 'Veuillez patienter…' : mode === 'login' ? 'Se connecter' : "S'inscrire" }}
      </button>
    </form>

    <button class="switch" type="button" @click="mode = mode === 'login' ? 'register' : 'login'">
      {{ mode === 'login' ? 'Pas de compte ? Créer un compte' : 'Déjà inscrit ? Se connecter' }}
    </button>
  </div>
</template>

<style scoped>
.card {
  width: 100%;
  max-width: 380px;
  padding: 2.25rem;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: var(--surface);
}
.title {
  margin: 0;
  font-size: 1.5rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--accent);
}
.subtitle {
  margin: 0.25rem 0 1.75rem;
  color: var(--muted);
  font-size: 0.95rem;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.85rem;
  color: var(--muted);
}
.field input {
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--bg);
  color: var(--text);
  font-size: 1rem;
}
.field input:focus {
  outline: 2px solid var(--accent);
  outline-offset: 1px;
}
.submit {
  margin-top: 0.5rem;
  padding: 0.7rem;
  border: none;
  border-radius: 8px;
  background: var(--accent);
  color: #06121f;
  font-weight: 600;
  cursor: pointer;
}
.submit:disabled {
  opacity: 0.6;
  cursor: progress;
}
.switch {
  margin-top: 1.25rem;
  width: 100%;
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  font-size: 0.85rem;
  text-decoration: underline;
}
.error {
  margin: 0;
  color: #ff9b9b;
  font-size: 0.85rem;
}
</style>
