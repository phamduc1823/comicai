<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue'
import AppFooter from '@/components/AppFooter.vue'
</script>

<template>
  <div class="layout-wrapper">
    <AppHeader />
    <main class="layout-main">
      <slot />
    </main>
    <AppFooter />
  </div>
</template>

<style scoped>
.layout-wrapper {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.layout-main {
  flex: 1;
  display: flex;
  flex-direction: column;
}
</style>
