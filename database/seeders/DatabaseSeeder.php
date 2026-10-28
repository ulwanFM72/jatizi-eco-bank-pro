<?php

namespace Database\Seeders;

use App\Models\JenisSampah;
use App\Models\Kelas;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Jenis sampah awal. Hanya an-organik daur ulang yang bernilai poin; tiap jenis punya aturan sendiri
        // (gram per unit, poin per unit). Angka di bawah hanya contoh: sesuaikan di menu Jenis Sampah.
        foreach ([
            ['Botol Plastik', 'anorganik', true, 1000, 5, 'Botol plastik bekas (PET) yang dapat didaur ulang'],
            ['Kardus dan Kertas', 'anorganik', true, 1000, 5, 'Kardus, kertas HVS, koran, dan sejenisnya'],
            ['Kaleng dan Logam', 'anorganik', true, 1000, 5, 'Kaleng minuman dan logam bekas'],
            ['Plastik Non Daur Ulang', 'anorganik', false, null, null, 'Kantong kresek, bungkus plastik, styrofoam (dicatat, tanpa poin)'],
            ['Sampah Organik', 'organik', false, null, null, 'Sisa makanan, daun, dan bahan yang mudah terurai (dicatat, tanpa poin)'],
            ['Sampah B3', 'b3', false, null, null, 'Baterai, lampu, elektronik (dicatat, tanpa poin)'],
        ] as [$nama, $kategori, $bernilai, $gram, $poin, $keterangan]) {
            JenisSampah::firstOrCreate(['nama' => $nama], [
                'kategori' => $kategori,
                'bernilai_poin' => $bernilai,
                'gram_per_unit' => $gram,
                'poin_per_unit' => $poin,
                'keterangan' => $keterangan,
                'aktif' => true,
            ]);
        }

        // Kelompok penampung setoran guru dan tenaga administrasi (tidak ikut peringkat kelas).
        Kelas::firstOrCreate(['nama' => 'Guru'], ['tipe' => 'guru']);
        Kelas::firstOrCreate(['nama' => 'Tenaga Administrasi'], ['tipe' => 'tendik']);

        // Akun admin awal. UBAH kata sandi ini setelah login pertama.
        User::firstOrCreate(['username' => 'admin'], [
            'name' => 'Administrator',
            'email' => 'admin@jatizi.local',
            'password' => env('SEED_ADMIN_PASSWORD', 'Jatizi@2026'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Data contoh hanya untuk lingkungan lokal.
        if (app()->environment('local')) {
            $this->dataContoh();
        }
    }

    private function dataContoh(): void
    {
        $kelas = collect(['X PPLG 1', 'X PPLG 2', 'XI PPLG 1', 'XII PPLG 1'])
            ->map(fn ($nama) => Kelas::firstOrCreate(['nama' => $nama], ['tipe' => 'kelas']));

        User::firstOrCreate(['username' => 'petugas'], [
            'name' => 'Petugas Bank Sampah',
            'email' => 'petugas@jatizi.local',
            'password' => 'Petugas@2026',
            'role' => 'petugas',
            'is_active' => true,
        ]);

        User::firstOrCreate(['username' => 'siswa1'], [
            'name' => 'Ahmad Fauzi',
            'email' => 'siswa1@jatizi.local',
            'password' => 'Siswa@2026',
            'role' => 'siswa',
            'nomor_induk' => '2025001',
            'kelas_id' => $kelas->first()->id,
            'is_active' => true,
        ]);

        User::firstOrCreate(['username' => 'guru1'], [
            'name' => 'Bu Siti Aminah',
            'email' => 'guru1@jatizi.local',
            'password' => 'Guru@2026',
            'role' => 'guru',
            'nomor_induk' => '198001012005012001',
            'kelas_id' => Kelas::where('tipe', 'guru')->value('id'),
            'is_active' => true,
        ]);
    }
}
