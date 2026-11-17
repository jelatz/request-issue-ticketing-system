<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import GuestLayout from '../../Layouts/GuestLayout.vue';

const page = usePage();

const flashError = computed(() => page.props.flash?.error || null);
const showDemoAccounts = ref(false);

const form = useForm({
    login: '',
    password: '',
    remember: false,
});

const demoAccounts = [
    {
        label: 'Superadmin',
        username: 'superadmin',
        password: 'password',
        roleHint: 'Full Admin & Triage',
    },
    {
        label: 'Lead Engineer',
        username: 'engineer1',
        password: 'password',
        roleHint: 'Platform Engineering',
    },
    {
        label: 'Network Engineer',
        username: 'engineer2',
        password: 'password',
        roleHint: 'NOC Infrastructure',
    },
    {
        label: 'Operations User',
        username: 'user1',
        password: 'password',
        roleHint: 'Standard Requester',
    },
    {
        label: 'Billing User',
        username: 'user2',
        password: 'password',
        roleHint: 'Finance & Billing',
    },
];

function fillDemoCredentials(account) {
    form.login = account.username;
    form.password = account.password;
    form.clearErrors();
}

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />

    <GuestLayout title="Sign in to your workspace">
        <div
            v-if="flashError"
            class="mb-4 px-3 py-2 rounded-md bg-rose-50 border border-rose-200 text-xs font-medium text-rose-800"
        >
            {{ flashError }}
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label for="login" class="block text-xs font-medium text-slate-700 mb-1">
                    Username or Email
                </label>
                <input
                    id="login"
                    v-model="form.login"
                    type="text"
                    autocomplete="username"
                    required
                    autofocus
                    placeholder="engineer1 or name@calltek.local"
                    class="w-full px-3 py-1.5 text-sm text-slate-900 bg-white border rounded-md placeholder:text-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    :class="form.errors.login ? 'border-rose-400' : 'border-slate-300'"
                />
                <p v-if="form.errors.login" class="mt-1 text-xs font-medium text-rose-600">
                    {{ form.errors.login }}
                </p>
            </div>

            <div>
                <label for="password" class="block text-xs font-medium text-slate-700 mb-1">
                    Password
                </label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    placeholder="Enter your password"
                    class="w-full px-3 py-1.5 text-sm text-slate-900 bg-white border rounded-md placeholder:text-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    :class="form.errors.password ? 'border-rose-400' : 'border-slate-300'"
                />
                <p v-if="form.errors.password" class="mt-1 text-xs font-medium text-rose-600">
                    {{ form.errors.password }}
                </p>
            </div>

            <div class="flex items-center justify-between pt-0.5">
                <label class="inline-flex items-center gap-2 text-xs text-slate-600 cursor-pointer select-none">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                    />
                    <span>Remember me</span>
                </label>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60 rounded-md transition-colors"
            >
                {{ form.processing ? 'Signing in...' : 'Sign in' }}
            </button>
        </form>

        <!-- Collapsible Demo Accounts Toggle -->
        <div class="mt-5 pt-4 border-t border-slate-200">
            <div class="flex items-center justify-between">
                <button
                    type="button"
                    class="text-xs font-medium text-slate-500 hover:text-slate-800 transition-colors"
                    :aria-expanded="showDemoAccounts"
                    @click="showDemoAccounts = !showDemoAccounts"
                >
                    Demo accounts {{ showDemoAccounts ? '(Hide)' : '(Show)' }}
                </button>
                <span v-if="showDemoAccounts" class="text-[11px] font-mono text-slate-400">
                    pw: password
                </span>
            </div>

            <div v-if="showDemoAccounts" class="mt-3 divide-y divide-slate-200 border border-slate-200 rounded-md overflow-hidden">
                <button
                    v-for="account in demoAccounts"
                    :key="account.username"
                    type="button"
                    class="w-full text-left px-3 py-2 bg-white hover:bg-slate-50 transition-colors flex items-center justify-between gap-2"
                    @click="fillDemoCredentials(account)"
                >
                    <div class="min-w-0">
                        <div class="text-xs font-medium text-slate-800">
                            {{ account.label }}
                        </div>
                        <div class="text-[11px] text-slate-500 truncate">
                            {{ account.roleHint }}
                        </div>
                    </div>
                    <span class="text-[11px] font-mono text-blue-600 shrink-0">
                        {{ account.username }}
                    </span>
                </button>
            </div>
        </div>
    </GuestLayout>
</template>
