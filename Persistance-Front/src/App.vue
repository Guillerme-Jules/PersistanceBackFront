<script setup>
import { onMounted } from 'vue'
import { useAuth } from '@/stores/auth'
import LoginForm from '@/components/LoginForm.vue'
import Dashboard from '@/components/Dashboard.vue'

const { isAuthenticated, initialize } = useAuth()

// Au chargement : restaure la session si un JWT valide est déjà en localStorage.
onMounted(initialize)
</script>

<template>
  <div class="app">
    <Dashboard v-if="isAuthenticated" />
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
</style>
