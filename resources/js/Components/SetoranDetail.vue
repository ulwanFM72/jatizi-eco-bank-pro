<script setup>
import Modal from './Modal.vue';
import { fmtGram, fmtNum, fmtTanggal, kategoriClass, kategoriLabel } from '../helpers';

defineProps({ setoran: Object }); // null = tertutup
const emit = defineEmits(['close']);

const fmtWaktu = (iso) =>
    iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : null;
</script>

<template>
    <Modal :show="!!setoran" title="Detail transaksi" max-width="max-w-lg" @close="emit('close')">
        <dl v-if="setoran" class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
            <dt class="text-slate-500">No. transaksi</dt>
            <dd class="col-span-2 font-medium">#{{ setoran.id }}</dd>

            <dt class="text-slate-500">Tanggal</dt>
            <dd class="col-span-2">{{ fmtTanggal(setoran.tanggal) }}</dd>

            <dt class="text-slate-500">Penyetor</dt>
            <dd class="col-span-2">{{ setoran.penyetor }}</dd>

            <dt class="text-slate-500">Kelas</dt>
            <dd class="col-span-2">{{ setoran.kelas }}</dd>

            <dt class="text-slate-500">Rincian sampah</dt>
            <dd class="col-span-2">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-slate-500">
                        <tr>
                            <th class="py-1 font-medium">Jenis</th>
                            <th class="py-1 text-right font-medium">Berat</th>
                            <th class="py-1 text-right font-medium">Poin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-100">
                        <tr v-for="it in setoran.items" :key="it.jenis_sampah_id">
                            <td class="py-1.5">
                                {{ it.jenis }}
                                <span :class="['badge ml-1', kategoriClass[it.kategori]]">{{ kategoriLabel[it.kategori] }}</span>
                            </td>
                            <td class="py-1.5 text-right tabular-nums">{{ fmtGram(it.berat_gram) }}</td>
                            <td class="py-1.5 text-right tabular-nums">
                                <span v-if="it.bernilai_poin">{{ fmtNum(it.poin) }}</span>
                                <span v-else class="text-xs text-slate-500">tanpa poin</span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-brand-200 font-semibold">
                            <td class="py-1.5">Total</td>
                            <td class="py-1.5 text-right tabular-nums">{{ fmtGram(setoran.berat_gram) }}</td>
                            <td class="py-1.5 text-right tabular-nums text-brand-800">{{ fmtNum(setoran.poin) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </dd>

            <dt class="text-slate-500">Status</dt>
            <dd class="col-span-2">{{ setoran.status === 'dikoreksi' ? 'Dikoreksi admin' : 'Valid (sesuai catatan awal)' }}</dd>

            <template v-if="setoran.petugas">
                <dt class="text-slate-500">Petugas penerima</dt>
                <dd class="col-span-2">{{ setoran.petugas }}</dd>
            </template>

            <template v-if="setoran.catatan">
                <dt class="text-slate-500">Catatan</dt>
                <dd class="col-span-2">{{ setoran.catatan }}</dd>
            </template>

            <template v-if="fmtWaktu(setoran.dicatat_pada)">
                <dt class="text-slate-500">Dicatat pada</dt>
                <dd class="col-span-2">{{ fmtWaktu(setoran.dicatat_pada) }}</dd>
            </template>

            <template v-if="fmtWaktu(setoran.dikoreksi_pada)">
                <dt class="text-slate-500">Dikoreksi pada</dt>
                <dd class="col-span-2">{{ fmtWaktu(setoran.dikoreksi_pada) }}</dd>
            </template>
        </dl>
    </Modal>
</template>
