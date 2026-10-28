<?php

namespace App\Services;

use App\Models\JenisSampah;
use App\Models\Kelas;
use App\Models\SaldoJenis;
use App\Models\Setoran;
use App\Models\SetoranItem;
use App\Models\User;
use App\Support\PointCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat poin dan sisa berat dihitung (di backend, bukan di browser).
 *
 * Satu setoran berisi satu atau lebih item (jenis sampah + berat). Aturan poin:
 *  - Hanya jenis yang bernilai poin (an-organik daur ulang) yang menghasilkan poin.
 *    Organik, B3, dan an-organik non daur ulang tetap tercatat beratnya, tetapi 0 poin.
 *  - Tiap jenis punya aturan sendiri (mis. 1.000 g = 5 poin) dan sisa berat sendiri.
 *    Berat antarjenis tidak digabung.
 *
 * Prinsip konsistensi:
 *  1. Setiap perubahan (catat / koreksi / hapus) berjalan dalam satu transaksi database.
 *  2. Baris kelas dan penyetor yang terdampak dikunci (SELECT ... FOR UPDATE) lebih dulu.
 *  3. Setelah data berubah, buku besar dihitung ulang dengan memutar ulang seluruh item aktif
 *     berurutan (setoran, lalu item). Hasilnya deterministik, jadi koreksi atau hapus tidak
 *     pernah membuat poin terhitung dua kali.
 *  4. Kunci idempotensi mencegah klik ganda / kirim ulang form mencatat setoran dua kali.
 */
class SetoranService
{
    /**
     * @param array{penyetor_id:?int,nama_penyetor:?string,kelas_id:?int,items:array<int,array{jenis_sampah_id:int,berat_gram:int}>,tanggal:string,catatan:?string,idempotency_key:?string} $data
     */
    public function catat(array $data, User $petugas): Setoran
    {
        $data = $this->lengkapiPenyetor($data);
        $items = $this->rapikanItems($data['items'] ?? []);

        return DB::transaction(function () use ($data, $items, $petugas) {
            // Penguncian harus menjadi pernyataan pertama dalam transaksi.
            $this->kunci([$data['kelas_id']], [$data['penyetor_id']]);

            if (! empty($data['idempotency_key'])) {
                $ada = Setoran::withTrashed()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($ada) {
                    return $ada; // kirim ulang: kembalikan yang sudah tersimpan
                }
            }

            $setoran = Setoran::create([
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'kelas_id' => $data['kelas_id'],
                'penyetor_id' => $data['penyetor_id'],
                'nama_penyetor' => $data['nama_penyetor'],
                'berat_gram' => array_sum(array_column($items, 'berat_gram')),
                'tanggal' => $data['tanggal'],
                'petugas_id' => $petugas->id, // petugas penerima selalu dari akun yang login
                'status' => 'valid',
                'catatan' => $data['catatan'] ?? null,
            ]);

            $this->simpanItems($setoran, $items, collect());
            $this->hitungUlang([$setoran->kelas_id], [$setoran->penyetor_id]);

            return $setoran->refresh();
        });
    }

    /** Koreksi setoran (khusus admin). Seluruh isi transaksi diganti lalu poin dihitung ulang. */
    public function koreksi(Setoran $setoran, array $data): Setoran
    {
        $data = $this->lengkapiPenyetor($data);
        $items = $this->rapikanItems($data['items'] ?? []);

        return DB::transaction(function () use ($setoran, $data, $items) {
            $this->kunci(
                [$setoran->kelas_id, $data['kelas_id']],
                [$setoran->penyetor_id, $data['penyetor_id']],
            );

            $s = Setoran::whereKey($setoran->id)->lockForUpdate()->firstOrFail();
            $kelasLama = $s->kelas_id;
            $userLama = $s->penyetor_id;
            $this->kunci([$kelasLama], [$userLama]); // aman bila ada perubahan bersamaan

            // Aturan poin lama untuk jenis yang sama dipertahankan (perubahan aturan hanya untuk setoran baru).
            $lama = $s->items()->get()->keyBy('jenis_sampah_id');
            $s->items()->delete();

            $s->update([
                'kelas_id' => $data['kelas_id'],
                'penyetor_id' => $data['penyetor_id'],
                'nama_penyetor' => $data['nama_penyetor'],
                'berat_gram' => array_sum(array_column($items, 'berat_gram')),
                'tanggal' => $data['tanggal'],
                'catatan' => $data['catatan'] ?? null,
                'status' => 'dikoreksi',
                'dikoreksi_pada' => now(),
            ]);

            $this->simpanItems($s, $items, $lama);

            if ($data['penyetor_id'] === null) {
                $s->forceFill(['poin_pribadi' => null])->save();
                DB::table('setoran_item')->where('setoran_id', $s->id)->update(['poin_pribadi' => null]);
            }

            $this->hitungUlang([$kelasLama, $s->kelas_id], [$userLama, $s->penyetor_id]);

            return $s->refresh();
        });
    }

    /** Hapus setoran (soft delete) lalu hitung ulang buku besar yang terdampak. */
    public function hapus(Setoran $setoran): void
    {
        DB::transaction(function () use ($setoran) {
            $this->kunci([$setoran->kelas_id], [$setoran->penyetor_id]);

            $s = Setoran::whereKey($setoran->id)->lockForUpdate()->firstOrFail();
            $kelasId = $s->kelas_id;
            $userId = $s->penyetor_id;
            $s->delete();

            $this->hitungUlang([$kelasId], [$userId]);
        });
    }

    /** Hitung ulang seluruh buku besar (dipakai setelah migrasi atau perubahan aturan besar). */
    public function hitungUlangSemua(): void
    {
        DB::transaction(function () {
            $this->hitungUlang(Kelas::pluck('id')->all(), User::pluck('id')->all());
        });
    }

    /**
     * Item dibersihkan: minimal satu, berat positif, dan satu jenis hanya boleh muncul sekali per transaksi.
     *
     * @return array<int, array{jenis_sampah_id:int, berat_gram:int}>
     */
    private function rapikanItems(array $items): array
    {
        $hasil = [];
        $terpakai = [];

        foreach ($items as $it) {
            $jenisId = (int) ($it['jenis_sampah_id'] ?? 0);
            $gram = (int) ($it['berat_gram'] ?? 0);

            if ($jenisId < 1 || $gram < 1) {
                throw ValidationException::withMessages(['items' => 'Setiap jenis sampah harus memiliki berat minimal 1 gram.']);
            }
            if (isset($terpakai[$jenisId])) {
                throw ValidationException::withMessages(['items' => 'Satu jenis sampah hanya boleh dipilih sekali dalam satu setoran.']);
            }

            $terpakai[$jenisId] = true;
            $hasil[] = ['jenis_sampah_id' => $jenisId, 'berat_gram' => $gram];
        }

        if (! $hasil) {
            throw ValidationException::withMessages(['items' => 'Pilih minimal satu jenis sampah dan isi beratnya.']);
        }

        return $hasil;
    }

    /**
     * Simpan item beserta snapshot aturan poin. Jenis yang sudah ada pada setoran lama (koreksi)
     * memakai aturan lamanya; jenis baru memakai aturan jenis saat ini. Jenis tanpa nilai poin: aturan NULL.
     *
     * @param Collection<int, SetoranItem> $lama
     */
    private function simpanItems(Setoran $setoran, array $items, Collection $lama): void
    {
        $jenis = JenisSampah::whereIn('id', array_column($items, 'jenis_sampah_id'))->get()->keyBy('id');

        foreach ($items as $it) {
            $j = $jenis[$it['jenis_sampah_id']];

            if ($lama->has($j->id)) {
                $gram = $lama[$j->id]->aturan_gram;
                $poin = $lama[$j->id]->aturan_poin;
            } elseif ($j->punyaAturanPoin()) {
                $gram = $j->gram_per_unit;
                $poin = $j->poin_per_unit;
            } else {
                $gram = null;
                $poin = null;
            }

            SetoranItem::create([
                'setoran_id' => $setoran->id,
                'jenis_sampah_id' => $j->id,
                'berat_gram' => $it['berat_gram'],
                'aturan_gram' => $gram,
                'aturan_poin' => $poin,
            ]);
        }
    }

    /**
     * Bila setoran dikaitkan ke akun penyetor, nama dan kelas diambil dari akun itu
     * (klien tidak bisa memalsukannya). Bila tidak, nama dan kelas wajib diisi manual.
     */
    private function lengkapiPenyetor(array $data): array
    {
        $data['penyetor_id'] = $data['penyetor_id'] ?? null;

        if ($data['penyetor_id']) {
            $user = User::findOrFail($data['penyetor_id']);

            if (! in_array($user->role, User::PENYETOR_ROLES, true) || ! $user->is_active) {
                throw ValidationException::withMessages([
                    'penyetor_id' => 'Akun penyetor harus berperan siswa, guru, atau tenaga administrasi dan masih aktif.',
                ]);
            }

            $data['nama_penyetor'] = $user->name;
            if ($user->kelas_id) {
                $data['kelas_id'] = $user->kelas_id;
            }
        }

        if (empty($data['kelas_id'])) {
            throw ValidationException::withMessages(['kelas_id' => 'Kelas wajib dipilih.']);
        }
        if (empty($data['nama_penyetor'])) {
            throw ValidationException::withMessages(['nama_penyetor' => 'Nama penyetor wajib diisi.']);
        }

        return $data;
    }

    /** Kunci baris kelas lalu user dengan urutan id naik agar tidak terjadi deadlock. */
    private function kunci(array $kelasIds, array $userIds): void
    {
        $kelasIds = collect($kelasIds)->filter()->unique()->sort()->values();
        if ($kelasIds->isNotEmpty()) {
            Kelas::whereIn('id', $kelasIds)->orderBy('id')->lockForUpdate()->get();
        }

        $userIds = collect($userIds)->filter()->unique()->sort()->values();
        if ($userIds->isNotEmpty()) {
            User::whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /** Hitung ulang buku besar kelas dan penyetor yang terdampak. */
    private function hitungUlang(array $kelasIds, array $userIds): void
    {
        foreach (collect($kelasIds)->filter()->unique() as $id) {
            $kelas = Kelas::find($id);
            if ($kelas) {
                $this->putarUlang('kelas', $kelas);
            }
        }

        foreach (collect($userIds)->filter()->unique() as $id) {
            $user = User::find($id);
            if ($user) {
                $this->putarUlang('user', $user);
            }
        }
    }

    /**
     * Putar ulang semua item aktif satu pemilik buku besar (kelas atau user), berurutan menurut
     * setoran lalu item. Sisa berat dihitung terpisah untuk tiap jenis sampah.
     */
    private function putarUlang(string $tipe, Model $pemilik): void
    {
        $kolomSetoran = $tipe === 'kelas' ? 'kelas_id' : 'penyetor_id';
        $kolomPoin = $tipe === 'kelas' ? 'poin' : 'poin_pribadi';

        $rows = SetoranItem::query()
            ->join('setoran', 'setoran.id', '=', 'setoran_item.setoran_id')
            ->whereNull('setoran.deleted_at')
            ->where("setoran.{$kolomSetoran}", $pemilik->id)
            ->orderBy('setoran.id')
            ->orderBy('setoran_item.id')
            ->get(['setoran_item.*']);

        $sisa = [];
        $gramJenis = [];
        $poinJenis = [];
        $poinSetoran = [];

        foreach ($rows as $it) {
            $j = $it->jenis_sampah_id;
            $sisa[$j] ??= 0;

            if ($it->aturan_gram) {
                $hasil = (new PointCalculator($it->aturan_gram, (int) $it->aturan_poin))->apply($sisa[$j], $it->berat_gram);
                $poin = $hasil['poin'];
                $sisa[$j] = $hasil['sisa_gram'];
            } else {
                $poin = 0; // jenis tidak bernilai poin
            }

            $gramJenis[$j] = ($gramJenis[$j] ?? 0) + $it->berat_gram;
            $poinJenis[$j] = ($poinJenis[$j] ?? 0) + $poin;
            $poinSetoran[$it->setoran_id] = ($poinSetoran[$it->setoran_id] ?? 0) + $poin;

            if ($it->{$kolomPoin} === null || (int) $it->{$kolomPoin} !== $poin) {
                DB::table('setoran_item')->where('id', $it->id)->update([$kolomPoin => $poin]);
            }
        }

        // Total poin per setoran (hanya yang berubah yang ditulis).
        if ($poinSetoran) {
            foreach (array_chunk(array_keys($poinSetoran), 500) as $ids) {
                $saatIni = DB::table('setoran')->whereIn('id', $ids)->pluck($kolomPoin, 'id');
                foreach ($ids as $sid) {
                    $cur = $saatIni[$sid] ?? null;
                    if ($cur === null || (int) $cur !== $poinSetoran[$sid]) {
                        DB::table('setoran')->where('id', $sid)->update([$kolomPoin => $poinSetoran[$sid]]);
                    }
                }
            }
        }

        // Buku besar per jenis ditulis ulang seluruhnya.
        SaldoJenis::where('pemilik_tipe', $tipe)->where('pemilik_id', $pemilik->id)->delete();
        foreach ($gramJenis as $jenisId => $gram) {
            SaldoJenis::create([
                'pemilik_tipe' => $tipe,
                'pemilik_id' => $pemilik->id,
                'jenis_sampah_id' => $jenisId,
                'total_gram' => $gram,
                'sisa_gram' => $sisa[$jenisId] ?? 0,
                'total_poin' => $poinJenis[$jenisId] ?? 0,
            ]);
        }

        // Kolom sisa_gram pada kelas/user tidak dipakai lagi (sisa kini per jenis di saldo_jenis).
        $pemilik->forceFill([
            'total_gram' => array_sum($gramJenis),
            'sisa_gram' => 0,
            'total_poin' => array_sum($poinJenis),
        ])->save();
    }
}
