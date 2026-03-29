<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-gray-50 via-orange-50/30 to-gray-50 dark:from-gray-950 dark:via-gray-900 dark:to-gray-950 px-4">
        <!-- Background subtle decoration -->
        <div class="fixed inset-0 overflow-hidden pointer-events-none">
            <div class="absolute -top-40 -right-40 w-80 h-80 bg-orange-100 dark:bg-orange-950/20 rounded-full opacity-40 blur-3xl"></div>
            <div class="absolute -bottom-40 -left-40 w-80 h-80 bg-orange-50 dark:bg-orange-950/10 rounded-full opacity-40 blur-3xl"></div>
        </div>

        <!-- Logo -->
        <div class="relative z-10 mb-8">
            <Link href="/" class="block transition-transform duration-200 hover:scale-105">
                <img
                    src="/assets/logo-icon-orange.svg"
                    alt="SR-Homes"
                    class="h-16 w-16"
                />
            </Link>
        </div>

        <!-- Card -->
        <div class="relative z-10 w-full max-w-md">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl shadow-gray-200/50 dark:shadow-black/20 border border-gray-100 dark:border-gray-800 px-8 py-8">
                <slot />
            </div>
        </div>

        <!-- Footer -->
        <div class="relative z-10 mt-8">
            <p class="text-xs text-gray-400 dark:text-gray-600">
                &copy; {{ new Date().getFullYear() }} SR-Homes Immobilien GmbH
            </p>
        </div>
    </div>
</template>
