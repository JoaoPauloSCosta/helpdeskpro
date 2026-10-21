<template>
    <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table
                class="min-w-full text-sm text-neutral-900 [&_td]:px-4 [&_td]:py-3 [&_td]:align-middle [&_tbody]:divide-y [&_tbody]:divide-neutral-200 [&_tbody_tr:hover]:bg-neutral-100 [&_th]:px-4 [&_th]:py-3 [&_th]:text-left [&_th]:text-xs [&_th]:font-semibold [&_th]:uppercase [&_th]:tracking-wide [&_th]:text-neutral-600 [&_thead]:bg-neutral-100"
            >
                <thead><slot name="head" /></thead>
                <tbody><slot /></tbody>
            </table>
        </div>
        <slot name="footer" />
    </div>
</template>
