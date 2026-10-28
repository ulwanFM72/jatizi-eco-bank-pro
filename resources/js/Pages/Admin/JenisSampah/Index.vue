<script setup>
import { ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Modal from '../../../Components/Modal.vue';
import { fmtNum, kategoriClass, kategoriLabel } from '../../../helpers';

defineProps({ jenis: Array });

const tampil = ref(false);
const form = useForm({
    id: null,
    nama: '',
    kategori: 'anorganik',
    keterangan: '',
    aktif: true,
    bernilai_poin: false,
    gram_per_unit: 1000,
    poin_per_unit: 5,
});

// Hanya an-organik yang boleh bernilai poin.
watch(
    () => form.kategori,
    (k) => {
        if (k !== 'anorganik') form.bernilai_poin = false;
    },
);

const tambah = () => {
    form.reset();
    form.clearErrors();
    form.id = null;
    tampil.value = true;
};

const ubah = (j) => {
    form.clearErrors();
    Object.assign(form, {
        id: j.id,
        nama: j.nama,
        kategori: j.kategori,
        keterangan: j.keterangan ?? '',
        aktif: !!j.aktif,
        bernilai_poin: !!j.bernilai_poin,
        gram_per_unit: j.gram_per_unit ?? 1000,
        poin_per_unit: j.poin_per_unit ?? 5,
    });
    tampil.value = true;
};

const simpan = () => {
    const opsi = { preserveScroll: true, onSuccess: () => (tampil.value = false) };
    if (form.id) form.put(`/admin/jenis-sampah/${form.id}`, opsi);
    else form.post('/admin/jenis-sampah', opsi);
};

const hapus = (j) => {
    if (window.confirm(`Hapus jenis sampah "${j.nama}"?`)) router.delete(`/admin/jenis-sampah/${j.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Jenis Sampah" />

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-brand-900">Manajemen Jenis Sampah</h1>
            <p class="text-sm text-slate-600">
                Hanya sampah <strong>an-organik daur ulang</strong> yang bernilai poin, dengan aturan tiap jenis.
                Organik, B3, dan jenis lain tetap dicatat beratnya tanpa poin.
            </p>
        </div>
        <button type="button" class="btn btn-primary" @click="tambah">+ Tambah jenis</button>
    </div>

    <div class="table-wrap bg-white">
        <table class="min-w-full divide-y divide-brand-100">
            <thead class="bg-brand-100">
                <tr>
                    <th class="th">Nama</th>
                    <th class="th">Kategori</th>
                    <th class="th">Aturan poin</th>
                    <th class="th">Keterangan</th>
                    <th class="th">Status</th>
                    <th class="th text-right">Dipakai</th>
                    <th class="th text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-50">
                <tr v-for="j in jenis" :key="j.id" class="hover:bg-brand-50/60">
                    <td class="td font-semibold">{{ j.nama }}</td>
                    <td class="td"><span :class="['badge', kategoriClass[j.kategori]]">{{ kategoriLabel[j.kategori] }}</span></td>
                    <td class="td whitespace-nowrap">
                        <span v-if="j.bernilai_poin" class="font-semibold text-brand-800">
                            {{ fmtNum(j.gram_per_unit) }} g = {{ fmtNum(j.poin_per_unit) }} poin
                        </span>
                        <span v-else class="text-slate-500">Tanpa poin</span>
                    </td>
                    <td class="td text-slate-600">{{ j.keterangan || '-' }}</td>
                    <td class="td">
                        <span :class="['badge', j.aktif ? 'bg-brand-100 text-brand-900' : 'bg-slate-200 text-slate-700']">
                            {{ j.aktif ? 'Aktif' : 'Nonaktif' }}
                        </span>
                    </td>
                    <td class="td text-right tabular-nums">{{ fmtNum(j.items_count) }}</td>
                    <td class="td whitespace-nowrap text-right">
                        <button type="button" class="btn btn-outline btn-sm" @click="ubah(j)">Ubah</button>
                        <button type="button" class="btn btn-danger btn-sm ml-1" @click="hapus(j)">Hapus</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <Modal :show="tampil" :title="form.id ? 'Ubah jenis sampah' : 'Tambah jenis sampah'" max-width="max-w-md" @close="tampil = false">
        <form class="space-y-4" @submit.prevent="simpan">
            <div>
                <label class="label" for="j-nama">Nama</label>
                <input id="j-nama" v-model="form.nama" type="text" class="input" maxlength="80" required />
                <p v-if="form.errors.nama" class="field-error">{{ form.errors.nama }}</p>
            </div>
            <div>
                <label class="label" for="j-kat">Kategori</label>
                <select id="j-kat" v-model="form.kategori" class="input">
                    <option v-for="(label, v) in kategoriLabel" :key="v" :value="v">{{ label }}</option>
                </select>
                <p v-if="form.errors.kategori" class="field-error">{{ form.errors.kategori }}</p>
            </div>
            <div>
                <label class="label" for="j-ket">Keterangan</label>
                <input id="j-ket" v-model="form.keterangan" type="text" class="input" maxlength="255" />
                <p v-if="form.errors.keterangan" class="field-error">{{ form.errors.keterangan }}</p>
            </div>

            <div class="rounded-xl border border-brand-200 bg-brand-50 p-3">
                <label :class="['flex items-center gap-2 text-sm font-medium', form.kategori !== 'anorganik' ? 'text-slate-400' : 'text-slate-800']">
                    <input
                        v-model="form.bernilai_poin"
                        type="checkbox"
                        :disabled="form.kategori !== 'anorganik'"
                        class="h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500"
                    />
                    Sampah daur ulang (bernilai poin)
                </label>
                <p v-if="form.kategori !== 'anorganik'" class="mt-1 text-xs text-slate-500">Hanya kategori an-organik yang dapat bernilai poin.</p>
                <p v-if="form.errors.bernilai_poin" class="field-error">{{ form.errors.bernilai_poin }}</p>

                <div v-if="form.bernilai_poin" class="mt-3 grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="j-gram">Setiap (gram)</label>
                        <input id="j-gram" v-model.number="form.gram_per_unit" type="number" min="1" max="100000" step="1" class="input" required />
                        <p v-if="form.errors.gram_per_unit" class="field-error">{{ form.errors.gram_per_unit }}</p>
                    </div>
                    <div>
                        <label class="label" for="j-poin">Mendapat (poin)</label>
                        <input id="j-poin" v-model.number="form.poin_per_unit" type="number" min="0" max="1000" step="1" class="input" required />
                        <p v-if="form.errors.poin_per_unit" class="field-error">{{ form.errors.poin_per_unit }}</p>
                    </div>
                    <p class="col-span-2 text-xs text-slate-600">
                        Sisa berat di bawah aturan ini tetap disimpan dan digabung ke setoran berikutnya untuk jenis yang sama.
                        Perubahan aturan hanya berlaku untuk setoran baru.
                    </p>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input v-model="form.aktif" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500" />
                Aktif (tampil di form setoran)
            </label>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-outline" @click="tampil = false">Batal</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Simpan</button>
            </div>
        </form>
    </Modal>
</template>
