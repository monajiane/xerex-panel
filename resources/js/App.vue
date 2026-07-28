<template>
    <router-view />
</template>

<script setup>
import { onMounted } from 'vue';
import { useAuthStore } from './stores/auth';

const auth = useAuthStore();

onMounted(async () => {
    if (auth.token) {
        try {
            await auth.fetchMe();
        } catch (e) {
            auth.logout();
        }
    }
});
</script>
