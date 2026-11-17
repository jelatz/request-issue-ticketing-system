<script setup>
defineProps({
    title: {
        type: String,
        default: 'Sign In',
    },
});
</script>

<template>
    <div class="min-h-screen bg-[#F8FAFC] flex flex-col justify-between">
        <main class="flex-1 flex flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
            <div class="w-full max-w-sm space-y-6">
                <div class="text-center space-y-1">
                    <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                        CallTek Desk
                    </h1>
                    <p class="text-xs text-slate-500">
                        Internal Service &amp; Engineering Portal
                    </p>
                </div>

                <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-slate-200">
                        <h2 class="text-sm font-semibold text-slate-900">
                            {{ title }}
                        </h2>
                    </div>

                    <div class="p-5">
                        <slot />
                    </div>
                </div>
            </div>
        </main>

        <footer class="py-4 text-center text-[11px] text-slate-400">
            CallTek Desk
        </footer>
    </div>
</template>
