<script setup>
import { ref, onMounted } from 'vue'
import { useAuth } from '@/stores/auth'
import LoginForm from '@/components/LoginForm.vue'
import Dashboard from '@/components/Dashboard.vue'

const { isAuthenticated, initialize } = useAuth()

// On attend la fin de initialize() (profil en ligne ou restauration de session
// dégradée hors-ligne) AVANT de choisir entre Dashboard et formulaire.
const ready = ref(false)

onMounted(async () => {
  await initialize()
  ready.value = true
})
</script>

<template>
  <div class="app">
    <p v-if="!ready" class="loading">Chargement…</p>
    <Dashboard v-else-if="isAuthenticated" />
    <LoginForm v-else />
  </div>
</template>

<style scoped>
.app {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem 1rem;
}
.loading {
  color: var(--muted);
  font-size: 0.95rem;
}
</style>
