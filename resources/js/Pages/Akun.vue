<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import StatCard from '../Components/StatCard.vue';
import SetoranTable from '../Components/SetoranTable.vue';
import SetoranDetail from '../Components/SetoranDetail.vue';
import { fmtGram, fmtNum, peranLabel } from '../helpers';

defineProps({ profil: Object, ringkas: Object, rincian: Array, terbaru: Array });

const detail = ref(null);

const persen = (r) => Math.min(100, Math.round((r.sisa_gram / r.gram_per_unit) * 100));
const kurang = (r) => Math.max(0, r.gram_per_unit - r.sisa_gram);
</script>

<template>
    <Head title="Akun Saya" />

    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-brand-900">Halo, {{ profil.name }}</h1>
            <p class="text-sm text-slate-600">
                {{ peranLabel[profil.role] }}
                <template v-if="profil.kelas"> · {{ profil.kelas }}</template>
                <template v-if="profil.nomor_induk"> · No. induk {{ profil.nomor_induk }}</template>
            </p>
        </div>
        <Link href="/riwayat" class="btn btn-soft btn-sm">Semua riwayat setoran</Link>
    </div>

    <section class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Ringkasan pribadi">
        <StatCard label="Akumulasi sampah" :value="fmtGram(ringkas.total_gram)" hint="Semua jenis sampah" />
        <StatCard label="Nilai setoran (poin)" :value="fmtNum(ringkas.total_poin)" hint="Dari sampah an-organik daur ulang" />
        <StatCard
            label="Peringkat kepedulian"
            :value="ringkas.peringkat ? `#${ringkas.peringkat}` : '-'"
            :hint="ringkas.peringkat ? `dari ${fmtNum(ringkas.dari_penyetor)} penyetor` : 'Belum ada setoran'"
        />
        <StatCard
            label="Peringkat kelas"
            :value="ringkas.peringkat_kelas ? `#${ringkas.peringkat_kelas}` : '-'"
            :hint="profil.kelas ?? 'Tanpa kelas'"
        />
    </section>

    <section class="card mt-5 p-5">
        <h2 class="text-lg font-bold text-brand-900">Menuju poin berikutnya</h2>
        <p class="mt-1 text-sm text-slate-600">
            Hanya sampah an-organik daur ulang yang bernilai poin. Sisa berat tiap jenis dihitung terpisah dan tidak hilang.
        </p>

        <ul v-if="rincian.length" class="mt-4 space-y-4">
            <li v-for="r in rincian" :key="r.jenis">
                <div class="flex flex-wrap items-center justify-between gap-1 text-sm">
                    <span class="font-semibold text-slate-800">{{ r.jenis }}</span>
                    <span class="text-xs text-slate-500">
                        setiap {{ fmtNum(r.gram_per_unit) }} g = {{ fmtNum(r.poin_per_unit) }} poin
                    </span>
                </div>
                <div class="bar-track mt-1.5" role="img" :aria-label="`${r.jenis}: ${persen(r)}% menuju poin berikutnya`">
                    <div class="bar-fill" :style="{ width: `${persen(r)}%` }" />
                </div>
                <p class="mt-1 text-xs text-slate-600">
                    Sisa tersimpan <strong>{{ fmtNum(r.sisa_gram) }} g</strong>, kurang
                    <strong>{{ fmtNum(kurang(r)) }} g</strong> lagi · total {{ fmtGram(r.total_gram) }} · {{ fmtNum(r.total_poin) }} poin
                </p>
            </li>
        </ul>
        <p v-else class="mt-4 text-sm text-slate-500">Belum ada jenis sampah daur ulang yang bernilai poin.</p>
    </section>

    <section class="mt-5">
        <h2 class="mb-3 text-lg font-bold text-brand-900">Setoran terbaru</h2>
        <SetoranTable :rows="terbaru" mode="pribadi" @detail="detail = $event" />
    </section>

    <SetoranDetail :setoran="detail" @close="detail = null" />
</template>
