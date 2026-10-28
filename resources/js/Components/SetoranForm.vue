<script setup>
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { fmtNum, hariIni, kategoriClass, kategoriLabel, kunciBaru } from '../helpers';

const props = defineProps({
    kelasOptions: { type: Array, required: true },
    jenisOptions: { type: Array, required: true },
    setoran: { type: Object, default: null }, // null = catat baru; objek = koreksi
});
const emit = defineEmits(['selesai']);

const page = usePage();
const petugas = computed(() => page.props.auth.user?.name);
const edit = computed(() => !!props.setoran);

const form = useForm({
    penyetor_id: props.setoran?.penyetor_id ?? null,
    nama_penyetor: props.setoran?.penyetor ?? '',
    kelas_id: props.setoran?.kelas_id ?? '',
    tanggal: props.setoran?.tanggal ?? hariIni(),
    catatan: props.setoran?.catatan ?? '',
    idempotency_key: kunciBaru(),
});

// ---- Pilihan jenis sampah: tiap jenis punya centang dan berat sendiri ----
const pilihan = reactive({});
for (const j of props.jenisOptions) {
    pilihan[j.id] = { aktif: false, berat: '', satuan: props.setoran ? 'gram' : 'kg' };
}
for (const it of props.setoran?.items ?? []) {
    if (pilihan[it.jenis_sampah_id]) {
        pilihan[it.jenis_sampah_id] = { aktif: true, berat: String(it.berat_gram), satuan: 'gram' };
    }
}

// Jenis pada setoran lama yang sudah dinonaktifkan tidak muncul di form; beri peringatan agar tidak hilang diam-diam.
const hilang = computed(() =>
    (props.setoran?.items ?? []).filter((it) => !props.jenisOptions.some((j) => j.id === it.jenis_sampah_id)),
);

const payload = computed(() =>
    props.jenisOptions
        .filter((j) => pilihan[j.id].aktif)
        .map((j) => ({ jenis_sampah_id: j.id, berat: pilihan[j.id].berat, satuan: pilihan[j.id].satuan })),
);

// Kirim hanya jenis yang dicentang.
form.transform((data) => ({ ...data, items: payload.value }));

const galat = (j, kolom) => {
    const idx = payload.value.findIndex((p) => p.jenis_sampah_id === j.id);
    return idx < 0 ? null : (form.errors[`items.${idx}.${kolom}`] ?? null);
};

const aturanLabel = (j) =>
    j.bernilai_poin ? `${fmtNum(j.gram_per_unit)} g = ${fmtNum(j.poin_per_unit)} poin` : 'Tanpa poin';

// Akun penyetor terpilih (nama dan kelas diambil dari akun, tidak diketik manual)
const terpilih = ref(
    props.setoran?.penyetor_id
        ? { id: props.setoran.penyetor_id, name: props.setoran.penyetor, kelas_id: props.setoran.kelas_id }
        : null,
);
const kelasTerkunci = computed(() => !!terpilih.value?.kelas_id);

// ---- Pencarian akun penyetor (siswa / guru / tendik) ----
const q = ref('');
const hasil = ref([]);
const mencari = ref(false);
let timer;
let kontrol;

watch(q, (v) => {
    clearTimeout(timer);
    kontrol?.abort();
    if (v.trim().length < 2) {
        hasil.value = [];
        return;
    }
    timer = setTimeout(async () => {
        kontrol = new AbortController();
        mencari.value = true;
        try {
            const r = await fetch(`/admin/penyetor/cari?q=${encodeURIComponent(v.trim())}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: kontrol.signal,
            });
            if (r.ok) hasil.value = await r.json();
        } catch (e) {
            if (e.name !== 'AbortError') hasil.value = [];
        } finally {
            mencari.value = false;
        }
    }, 250);
});
onUnmounted(() => {
    clearTimeout(timer);
    kontrol?.abort();
});

const pilih = (u) => {
    terpilih.value = u;
    form.penyetor_id = u.id;
    form.nama_penyetor = u.name;
    if (u.kelas_id) form.kelas_id = u.kelas_id;
    q.value = '';
    hasil.value = [];
};

const lepas = () => {
    terpilih.value = null;
    form.penyetor_id = null;
    form.nama_penyetor = '';
};

const kirim = () => {
    const opsi = {
        preserveScroll: true,
        onSuccess: () => {
            if (edit.value) {
                emit('selesai');
                return;
            }
            // Siap untuk setoran berikutnya; kunci baru supaya setoran berikutnya tidak dianggap kirim ulang.
            for (const j of props.jenisOptions) pilihan[j.id] = { aktif: false, berat: '', satuan: 'kg' };
            form.reset('catatan');
            lepas();
            form.kelas_id = '';
            form.idempotency_key = kunciBaru();
            form.clearErrors();
        },
    };
    if (edit.value) form.put(`/admin/setoran/${props.setoran.id}`, opsi);
    else form.post('/admin/setoran', opsi);
};
</script>

<template>
    <form class="space-y-4" @submit.prevent="kirim">
        <!-- Penyetor -->
        <div>
            <label class="label" for="cari-penyetor">Penyetor (akun)</label>
            <div v-if="terpilih" class="flex items-center justify-between rounded-xl border border-brand-300 bg-brand-50 px-3 py-2 text-sm">
                <span>
                    <strong>{{ terpilih.name }}</strong>
                    <span v-if="terpilih.kelas" class="text-slate-600"> · {{ terpilih.kelas }}</span>
                </span>
                <button type="button" class="text-xs font-semibold text-red-700 hover:underline" @click="lepas">Lepas</button>
            </div>
            <div v-else class="relative">
                <input
                    id="cari-penyetor"
                    v-model="q"
                    type="search"
                    class="input"
                    placeholder="Cari nama atau nomor induk (opsional)…"
                    autocomplete="off"
                />
                <ul
                    v-if="hasil.length"
                    class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-brand-200 bg-white shadow-lg"
                >
                    <li v-for="u in hasil" :key="u.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-brand-50"
                            @click="pilih(u)"
                        >
                            <span>{{ u.name }}</span>
                            <span class="text-xs text-slate-500">{{ u.kelas ?? u.role }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else-if="mencari" class="mt-1 text-xs text-slate-500">Mencari…</p>
            </div>
            <p v-if="form.errors.penyetor_id" class="field-error">{{ form.errors.penyetor_id }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="nama_penyetor">Nama penyetor</label>
                <input
                    id="nama_penyetor"
                    v-model="form.nama_penyetor"
                    type="text"
                    class="input"
                    :disabled="!!terpilih"
                    placeholder="Diisi otomatis bila akun dipilih"
                    maxlength="100"
                />
                <p v-if="form.errors.nama_penyetor" class="field-error">{{ form.errors.nama_penyetor }}</p>
            </div>

            <div>
                <label class="label" for="kelas_id">Kelas</label>
                <select id="kelas_id" v-model="form.kelas_id" class="input" :disabled="kelasTerkunci">
                    <option value="" disabled>Pilih kelas…</option>
                    <option v-for="k in kelasOptions" :key="k.id" :value="k.id">{{ k.nama }}</option>
                </select>
                <p v-if="kelasTerkunci" class="mt-1 text-xs text-slate-500">Mengikuti kelas pada akun penyetor.</p>
                <p v-if="form.errors.kelas_id" class="field-error">{{ form.errors.kelas_id }}</p>
            </div>
        </div>

        <!-- Jenis sampah: boleh lebih dari satu, tiap jenis dengan beratnya sendiri -->
        <fieldset>
            <legend class="label">Jenis sampah dan berat <span class="font-normal text-slate-500">(centang yang disetor)</span></legend>

            <p v-if="hilang.length" class="mb-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900">
                Setoran ini memuat jenis yang sudah nonaktif ({{ hilang.map((h) => h.jenis).join(', ') }}). Aktifkan kembali jenis itu di menu Jenis Sampah sebelum mengoreksi, agar tidak terhapus dari setoran.
            </p>

            <div class="space-y-2">
                <div
                    v-for="j in jenisOptions"
                    :key="j.id"
                    :class="[
                        'rounded-xl border px-3 py-2.5 transition',
                        pilihan[j.id].aktif ? 'border-brand-700 bg-brand-100' : 'border-slate-300 bg-white',
                    ]"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label class="flex cursor-pointer items-center gap-2 text-sm font-medium">
                            <input
                                v-model="pilihan[j.id].aktif"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500"
                            />
                            {{ j.nama }}
                            <span :class="['badge', kategoriClass[j.kategori]]">{{ kategoriLabel[j.kategori] }}</span>
                        </label>
                        <span :class="['text-xs', j.bernilai_poin ? 'font-semibold text-brand-800' : 'text-slate-500']">
                            {{ aturanLabel(j) }}
                        </span>
                    </div>

                    <div v-if="pilihan[j.id].aktif" class="mt-2 flex gap-2 sm:max-w-xs">
                        <input
                            v-model="pilihan[j.id].berat"
                            type="number"
                            inputmode="decimal"
                            :step="pilihan[j.id].satuan === 'kg' ? '0.001' : '1'"
                            min="0"
                            class="input"
                            placeholder="Berat"
                            :aria-label="`Berat ${j.nama}`"
                            required
                        />
                        <select v-model="pilihan[j.id].satuan" class="input w-24" :aria-label="`Satuan berat ${j.nama}`">
                            <option value="kg">kg</option>
                            <option value="gram">gram</option>
                        </select>
                    </div>
                    <p v-if="galat(j, 'berat')" class="field-error">{{ galat(j, 'berat') }}</p>
                    <p v-if="galat(j, 'satuan')" class="field-error">{{ galat(j, 'satuan') }}</p>
                    <p v-if="galat(j, 'jenis_sampah_id')" class="field-error">{{ galat(j, 'jenis_sampah_id') }}</p>
                </div>
            </div>
            <p v-if="form.errors.items" class="field-error">{{ form.errors.items }}</p>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="tanggal">Tanggal setoran</label>
                <input id="tanggal" v-model="form.tanggal" type="date" :max="hariIni()" class="input" required />
                <p v-if="form.errors.tanggal" class="field-error">{{ form.errors.tanggal }}</p>
            </div>

            <div>
                <label class="label" for="petugas">Petugas penerima</label>
                <input id="petugas" type="text" class="input" :value="setoran?.petugas ?? petugas" disabled />
            </div>
        </div>

        <div>
            <label class="label" for="catatan">Catatan (opsional)</label>
            <input id="catatan" v-model="form.catatan" type="text" class="input" maxlength="255" />
            <p v-if="form.errors.catatan" class="field-error">{{ form.errors.catatan }}</p>
        </div>

        <p class="rounded-xl bg-brand-50 px-3 py-2 text-xs text-slate-600">
            Poin hanya diberikan untuk sampah an-organik daur ulang, dihitung otomatis oleh server per jenis sampah. Jenis lain tetap dicatat beratnya tanpa poin.
        </p>

        <div class="flex justify-end">
            <button type="submit" class="btn btn-primary" :disabled="form.processing || !payload.length">
                {{ form.processing ? 'Menyimpan…' : edit ? 'Simpan koreksi' : 'Simpan setoran' }}
            </button>
        </div>
    </form>
</template>
