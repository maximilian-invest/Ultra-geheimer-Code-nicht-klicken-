<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const mounted = ref(false);
const emailFocused = ref(false);
const passwordFocused = ref(false);
const showPassword = ref(false);

onMounted(() => {
    setTimeout(() => { mounted.value = true; }, 100);
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Login" />

    <div class="login-page min-h-screen flex flex-col lg:flex-row">
        <!-- Left Side — Visual Showcase -->
        <div class="left-panel relative w-full lg:w-[60%] min-h-[240px] lg:min-h-screen overflow-hidden flex items-center justify-center">
            <!-- Animated gradient background -->
            <div class="absolute inset-0 bg-gradient-animate"></div>

            <!-- Floating geometric shapes -->
            <div class="absolute inset-0 overflow-hidden">
                <div class="shape shape-1"></div>
                <div class="shape shape-2"></div>
                <div class="shape shape-3"></div>
                <div class="shape shape-4"></div>
                <div class="shape shape-5"></div>
                <div class="shape shape-6"></div>
                <div class="shape shape-7"></div>
            </div>

            <!-- Subtle grid pattern overlay -->
            <div class="absolute inset-0 grid-pattern opacity-[0.04]"></div>

            <!-- Content -->
            <div class="relative z-10 text-center px-8 lg:px-16" :class="{ 'animate-fade-in': mounted }">
                <img
                    src="/assets/logo-full-white.svg"
                    alt="SR-Homes"
                    class="h-14 lg:h-20 mx-auto mb-6 lg:mb-10 drop-shadow-2xl"
                />
                <div class="hidden lg:block">
                    <h2 class="text-white/90 text-2xl xl:text-3xl font-light tracking-wide mb-4 font-display">
                        Ihr Partner für Immobilien
                    </h2>
                    <p class="text-white/60 text-lg tracking-widest uppercase">
                        Salzburg &middot; Österreich
                    </p>
                    <div class="mt-10 flex items-center justify-center gap-8 text-white/40 text-sm">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3H21m-3.75 3H21"/></svg>
                            <span>Vermietung</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205l3 1m1.5.5l-1.5-.5M6.75 7.364V3h-3v18m3-13.636l10.5-3.819"/></svg>
                            <span>Verwaltung</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                            <span>Vertrauen</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side — Login Form -->
        <div class="right-panel w-full lg:w-[40%] flex items-center justify-center bg-white dark:bg-gray-950 relative">
            <!-- Subtle decorative corner -->
            <div class="absolute top-0 right-0 w-64 h-64 bg-gradient-to-bl from-orange-50 dark:from-orange-950/20 to-transparent rounded-bl-full opacity-60"></div>

            <div class="w-full max-w-md px-8 sm:px-12 py-12 lg:py-0 relative z-10"
                 :class="{ 'animate-slide-up': mounted }">

                <!-- Logo icon for form side -->
                <div class="flex justify-center lg:justify-start mb-10">
                    <img
                        src="/assets/logo-icon-orange.svg"
                        alt="SR-Homes"
                        class="h-12 w-12"
                    />
                </div>

                <!-- Welcome text -->
                <div class="mb-8">
                    <h1 class="text-3xl font-display font-bold text-gray-900 dark:text-white mb-2"
                        :class="{ 'animate-text-reveal': mounted }">
                        Willkommen zurück
                    </h1>
                    <p class="text-gray-500 dark:text-gray-400 text-base"
                       :class="{ 'animate-text-reveal-delay': mounted }">
                        Melden Sie sich in Ihrem Kundenportal an
                    </p>
                </div>

                <!-- Status message -->
                <div v-if="status"
                     class="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800">
                    <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">{{ status }}</p>
                </div>

                <!-- Login Form -->
                <form @submit.prevent="submit" class="space-y-5">
                    <!-- Email Field -->
                    <div class="form-group">
                        <div class="relative">
                            <input
                                id="email"
                                type="email"
                                v-model="form.email"
                                required
                                autofocus
                                autocomplete="username"
                                placeholder=" "
                                @focus="emailFocused = true"
                                @blur="emailFocused = false"
                                class="form-input peer w-full h-14 px-4 pt-5 pb-2 rounded-xl border-2 bg-gray-50 dark:bg-gray-900
                                       text-gray-900 dark:text-white text-base
                                       transition-all duration-300 ease-out
                                       placeholder-transparent
                                       focus:bg-white dark:focus:bg-gray-900
                                       outline-none"
                                :class="[
                                    form.errors.email
                                        ? 'border-red-400 dark:border-red-500 focus:border-red-500 focus:ring-4 focus:ring-red-100 dark:focus:ring-red-900/30'
                                        : 'border-gray-200 dark:border-gray-700 focus:border-[#ee7606] focus:ring-4 focus:ring-orange-100 dark:focus:ring-orange-900/30'
                                ]"
                            />
                            <label
                                for="email"
                                class="floating-label absolute left-4 transition-all duration-200 ease-out pointer-events-none
                                       text-gray-400 dark:text-gray-500
                                       peer-placeholder-shown:top-4 peer-placeholder-shown:text-base
                                       peer-focus:top-2 peer-focus:text-xs
                                       top-2 text-xs"
                                :class="{ 'text-[#ee7606] dark:text-orange-400': emailFocused && !form.errors.email, 'text-red-500': form.errors.email }"
                            >
                                E-Mail-Adresse
                            </label>
                            <!-- Email icon -->
                            <div class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-300 dark:text-gray-600 transition-colors duration-200"
                                 :class="{ 'text-[#ee7606] dark:text-orange-400': emailFocused }">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                </svg>
                            </div>
                        </div>
                        <transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 -translate-y-2 max-h-0" enter-to-class="opacity-100 translate-y-0 max-h-10" leave-active-class="transition-all duration-200 ease-in" leave-from-class="opacity-100 translate-y-0 max-h-10" leave-to-class="opacity-0 -translate-y-2 max-h-0">
                            <p v-if="form.errors.email" class="mt-2 text-sm text-red-500 dark:text-red-400 flex items-center gap-1.5">
                                <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd" /></svg>
                                {{ form.errors.email }}
                            </p>
                        </transition>
                    </div>

                    <!-- Password Field -->
                    <div class="form-group">
                        <div class="relative">
                            <input
                                id="password"
                                :type="showPassword ? 'text' : 'password'"
                                v-model="form.password"
                                required
                                autocomplete="current-password"
                                placeholder=" "
                                @focus="passwordFocused = true"
                                @blur="passwordFocused = false"
                                class="form-input peer w-full h-14 px-4 pt-5 pb-2 pr-12 rounded-xl border-2 bg-gray-50 dark:bg-gray-900
                                       text-gray-900 dark:text-white text-base
                                       transition-all duration-300 ease-out
                                       placeholder-transparent
                                       focus:bg-white dark:focus:bg-gray-900
                                       outline-none"
                                :class="[
                                    form.errors.password
                                        ? 'border-red-400 dark:border-red-500 focus:border-red-500 focus:ring-4 focus:ring-red-100 dark:focus:ring-red-900/30'
                                        : 'border-gray-200 dark:border-gray-700 focus:border-[#ee7606] focus:ring-4 focus:ring-orange-100 dark:focus:ring-orange-900/30'
                                ]"
                            />
                            <label
                                for="password"
                                class="floating-label absolute left-4 transition-all duration-200 ease-out pointer-events-none
                                       text-gray-400 dark:text-gray-500
                                       peer-placeholder-shown:top-4 peer-placeholder-shown:text-base
                                       peer-focus:top-2 peer-focus:text-xs
                                       top-2 text-xs"
                                :class="{ 'text-[#ee7606] dark:text-orange-400': passwordFocused && !form.errors.password, 'text-red-500': form.errors.password }"
                            >
                                Passwort
                            </label>
                            <!-- Toggle password visibility -->
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-300 dark:text-gray-600
                                       hover:text-gray-500 dark:hover:text-gray-400 transition-colors duration-200 focus:outline-none"
                                :class="{ 'text-[#ee7606] dark:text-orange-400': passwordFocused }"
                                tabindex="-1"
                            >
                                <svg v-if="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            </button>
                        </div>
                        <transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="opacity-0 -translate-y-2 max-h-0" enter-to-class="opacity-100 translate-y-0 max-h-10" leave-active-class="transition-all duration-200 ease-in" leave-from-class="opacity-100 translate-y-0 max-h-10" leave-to-class="opacity-0 -translate-y-2 max-h-0">
                            <p v-if="form.errors.password" class="mt-2 text-sm text-red-500 dark:text-red-400 flex items-center gap-1.5">
                                <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd" /></svg>
                                {{ form.errors.password }}
                            </p>
                        </transition>
                    </div>

                    <!-- Remember me & Forgot password row -->
                    <div class="flex items-center justify-between pt-1">
                        <label class="flex items-center gap-3 cursor-pointer group select-none">
                            <div class="relative">
                                <input
                                    type="checkbox"
                                    v-model="form.remember"
                                    class="sr-only peer"
                                />
                                <div class="w-5 h-5 rounded-md border-2 border-gray-300 dark:border-gray-600
                                            transition-all duration-200 ease-out
                                            peer-checked:bg-[#ee7606] peer-checked:border-[#ee7606]
                                            peer-focus:ring-4 peer-focus:ring-orange-100 dark:peer-focus:ring-orange-900/30
                                            group-hover:border-gray-400 dark:group-hover:border-gray-500">
                                </div>
                                <svg class="absolute top-0.5 left-0.5 w-4 h-4 text-white pointer-events-none opacity-0 peer-checked:opacity-100 transition-opacity duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <span class="text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-800 dark:group-hover:text-gray-300 transition-colors">
                                Angemeldet bleiben
                            </span>
                        </label>

                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="text-sm font-medium text-[#ee7606] hover:text-orange-700 dark:hover:text-orange-300
                                   transition-colors duration-200 hover:underline underline-offset-4"
                        >
                            Passwort vergessen?
                        </Link>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-3">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="submit-btn relative w-full h-14 rounded-xl font-semibold text-base text-white
                                   bg-gradient-to-r from-[#ee7606] to-[#f59e0b]
                                   hover:from-[#d96a05] hover:to-[#e08e0a]
                                   focus:outline-none focus:ring-4 focus:ring-orange-200 dark:focus:ring-orange-900/40
                                   transition-all duration-300 ease-out
                                   transform hover:translate-y-[-1px] hover:shadow-lg hover:shadow-orange-500/25
                                   active:translate-y-0 active:shadow-md
                                   disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:translate-y-0 disabled:hover:shadow-none
                                   overflow-hidden"
                        >
                            <span class="relative z-10 flex items-center justify-center gap-2">
                                <!-- Spinner -->
                                <svg v-if="form.processing" class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ form.processing ? 'Wird angemeldet...' : 'Anmelden' }}</span>
                                <svg v-if="!form.processing" class="w-5 h-5 transition-transform duration-200 group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </span>
                            <!-- Ripple overlay -->
                            <div class="absolute inset-0 bg-white/10 opacity-0 hover:opacity-100 transition-opacity duration-300"></div>
                        </button>
                    </div>
                </form>

                <!-- Footer -->
                <div class="mt-10 pt-8 border-t border-gray-100 dark:border-gray-800">
                    <p class="text-center text-xs text-gray-400 dark:text-gray-600">
                        &copy; {{ new Date().getFullYear() }} SR-Homes Immobilien GmbH. Alle Rechte vorbehalten.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* === Animated Gradient Background === */
.bg-gradient-animate {
    background: linear-gradient(-45deg, #1a1a2e, #16213e, #0f3460, #1a1a2e, #2d1810, #1a1a2e);
    background-size: 400% 400%;
    animation: gradientShift 15s ease infinite;
}

@keyframes gradientShift {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* === Grid Pattern === */
.grid-pattern {
    background-image:
        linear-gradient(rgba(255,255,255,.1) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.1) 1px, transparent 1px);
    background-size: 60px 60px;
}

/* === Floating Shapes === */
.shape {
    position: absolute;
    border-radius: 50%;
    opacity: 0.08;
    filter: blur(1px);
}

.shape-1 {
    width: 300px; height: 300px;
    background: linear-gradient(135deg, #ee7606, #f59e0b);
    top: -50px; left: -50px;
    animation: float1 20s ease-in-out infinite;
}
.shape-2 {
    width: 200px; height: 200px;
    background: linear-gradient(135deg, #cdc5bf, #ee7606);
    bottom: 10%; right: 10%;
    animation: float2 25s ease-in-out infinite;
}
.shape-3 {
    width: 150px; height: 150px;
    background: #ee7606;
    top: 40%; left: 20%;
    border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
    animation: float3 18s ease-in-out infinite;
}
.shape-4 {
    width: 100px; height: 100px;
    background: linear-gradient(45deg, #f59e0b, #ee7606);
    top: 20%; right: 25%;
    border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%;
    animation: float4 22s ease-in-out infinite;
}
.shape-5 {
    width: 250px; height: 250px;
    background: linear-gradient(135deg, transparent, #ee7606);
    bottom: -80px; left: 30%;
    animation: float5 28s ease-in-out infinite;
}
.shape-6 {
    width: 80px; height: 80px;
    border: 2px solid rgba(238, 118, 6, 0.15);
    background: transparent;
    top: 60%; left: 10%;
    animation: float6 16s ease-in-out infinite;
}
.shape-7 {
    width: 120px; height: 120px;
    border: 2px solid rgba(238, 118, 6, 0.1);
    background: transparent;
    top: 15%; left: 55%;
    border-radius: 30% 70% 50% 50% / 50% 50% 70% 30%;
    animation: float3 24s ease-in-out infinite reverse;
}

@keyframes float1 {
    0%, 100% { transform: translate(0, 0) rotate(0deg) scale(1); }
    33% { transform: translate(30px, -30px) rotate(120deg) scale(1.1); }
    66% { transform: translate(-20px, 20px) rotate(240deg) scale(0.95); }
}
@keyframes float2 {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(-40px, -30px) rotate(180deg); }
}
@keyframes float3 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    25% { transform: translate(20px, -40px) scale(1.05); }
    50% { transform: translate(-10px, -20px) scale(0.95); }
    75% { transform: translate(30px, 10px) scale(1.02); }
}
@keyframes float4 {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    33% { transform: translate(-25px, 25px) rotate(60deg); }
    66% { transform: translate(15px, -35px) rotate(-30deg); }
}
@keyframes float5 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(50px, -40px) scale(1.1); }
}
@keyframes float6 {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    50% { transform: translate(20px, -20px) rotate(180deg); }
}

/* === Entrance Animations === */
.animate-fade-in {
    animation: fadeIn 1s ease-out forwards;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-slide-up {
    animation: slideUp 0.8s ease-out forwards;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-text-reveal {
    animation: textReveal 0.8s ease-out 0.2s both;
}

.animate-text-reveal-delay {
    animation: textReveal 0.8s ease-out 0.4s both;
}

@keyframes textReveal {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}

/* === Form Input Styling === */
.form-input {
    font-family: 'Inter', system-ui, sans-serif;
}

/* === Submit Button Shimmer === */
.submit-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
    transition: left 0.5s ease;
}

.submit-btn:hover::before {
    left: 100%;
}

/* === Responsive adjustments === */
@media (max-width: 1023px) {
    .left-panel {
        min-height: 200px;
    }
}
</style>
