<?php

namespace Tests\Feature;

use App\Models\JenisSampah;
use App\Models\Kelas;
use App\Models\SaldoJenis;
use App\Models\Setoran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SetoranTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $petugas;
    private Kelas $kelas;
    private JenisSampah $plastik;   // an-organik daur ulang, 1.000 g = 5 poin
    private JenisSampah $kardus;    // an-organik daur ulang, 2.000 g = 5 poin (aturan berbeda)
    private JenisSampah $organik;   // tanpa poin
    private JenisSampah $b3;        // tanpa poin

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->admin = User::factory()->create(['role' => 'admin', 'username' => 'admin']);
        $this->petugas = User::factory()->create(['role' => 'petugas', 'username' => 'petugas']);
        $this->kelas = Kelas::create(['nama' => 'X PPLG 1', 'tipe' => 'kelas']);

        $this->plastik = JenisSampah::create(['nama' => 'Botol Plastik', 'kategori' => 'anorganik', 'aktif' => true, 'bernilai_poin' => true, 'gram_per_unit' => 1000, 'poin_per_unit' => 5]);
        $this->kardus = JenisSampah::create(['nama' => 'Kardus', 'kategori' => 'anorganik', 'aktif' => true, 'bernilai_poin' => true, 'gram_per_unit' => 2000, 'poin_per_unit' => 5]);
        $this->organik = JenisSampah::create(['nama' => 'Sampah Organik', 'kategori' => 'organik', 'aktif' => true, 'bernilai_poin' => false]);
        $this->b3 = JenisSampah::create(['nama' => 'Sampah B3', 'kategori' => 'b3', 'aktif' => true, 'bernilai_poin' => false]);
    }

    private function item(JenisSampah $jenis, string $berat, string $satuan = 'gram'): array
    {
        return ['jenis_sampah_id' => $jenis->id, 'berat' => $berat, 'satuan' => $satuan];
    }

    private function setor(array $items, ?string $kunci = null)
    {
        return $this->actingAs($this->petugas)->post('/admin/setoran', [
            'nama_penyetor' => 'Budi Santoso',
            'kelas_id' => $this->kelas->id,
            'tanggal' => today()->toDateString(),
            'items' => $items,
            'idempotency_key' => $kunci ?? (string) Str::uuid(),
        ]);
    }

    private function saldo(JenisSampah $jenis): ?SaldoJenis
    {
        return SaldoJenis::where(['pemilik_tipe' => 'kelas', 'pemilik_id' => $this->kelas->id, 'jenis_sampah_id' => $jenis->id])->first();
    }

    public function test_satu_setoran_dapat_memuat_beberapa_jenis_dengan_berat_masing_masing(): void
    {
        $this->setor([
            $this->item($this->plastik, '1200'),
            $this->item($this->organik, '3', 'kg'),
        ])->assertSessionHasNoErrors();

        $setoran = Setoran::with('items')->first();
        $this->assertCount(2, $setoran->items);
        $this->assertSame(4200, $setoran->berat_gram);
        $this->assertSame(5, $setoran->poin); // hanya plastik: 1.200 g -> 5 poin
    }

    public function test_hanya_anorganik_daur_ulang_yang_bernilai_poin(): void
    {
        $this->setor([
            $this->item($this->organik, '5', 'kg'),
            $this->item($this->b3, '2', 'kg'),
        ])->assertSessionHasNoErrors();

        $this->kelas->refresh();
        $this->assertSame(0, $this->kelas->total_poin);
        $this->assertSame(7000, $this->kelas->total_gram); // berat tetap tercatat
    }

    public function test_sisa_berat_dan_aturan_dihitung_terpisah_per_jenis(): void
    {
        // 600 g plastik + 600 g kardus tidak dijumlahkan menjadi 1.200 g.
        $this->setor([$this->item($this->plastik, '600'), $this->item($this->kardus, '600')]);
        $this->assertSame(0, $this->kelas->refresh()->total_poin);
        $this->assertSame(600, $this->saldo($this->plastik)->sisa_gram);
        $this->assertSame(600, $this->saldo($this->kardus)->sisa_gram);

        // +400 g plastik melengkapi 1.000 g (5 poin); kardus (aturan 2.000 g) masih belum.
        $this->setor([$this->item($this->plastik, '400')]);
        $this->assertSame(5, $this->kelas->refresh()->total_poin);
        $this->assertSame(0, $this->saldo($this->plastik)->sisa_gram);
        $this->assertSame(600, $this->saldo($this->kardus)->sisa_gram);
    }

    public function test_aturan_berbeda_tiap_jenis(): void
    {
        $this->setor([$this->item($this->kardus, '2', 'kg')]);

        $this->assertSame(5, $this->kelas->refresh()->total_poin); // 2.000 g kardus = 5 poin
    }

    public function test_kirim_ulang_dengan_kunci_sama_tidak_menghitung_poin_dua_kali(): void
    {
        $kunci = (string) Str::uuid();

        $this->setor([$this->item($this->plastik, '1', 'kg')], $kunci);
        $this->setor([$this->item($this->plastik, '1', 'kg')], $kunci);

        $this->assertSame(1, Setoran::count());
        $this->assertSame(5, $this->kelas->refresh()->total_poin);
    }

    public function test_koreksi_menghitung_ulang_tanpa_menggandakan_poin(): void
    {
        $this->setor([$this->item($this->plastik, '800')]);
        $this->setor([$this->item($this->plastik, '400')]);
        $pertama = Setoran::orderBy('id')->first();

        $this->actingAs($this->admin)->put("/admin/setoran/{$pertama->id}", [
            'nama_penyetor' => 'Budi Santoso',
            'kelas_id' => $this->kelas->id,
            'tanggal' => today()->toDateString(),
            'items' => [$this->item($this->plastik, '1000')],
        ])->assertSessionHasNoErrors();

        $this->kelas->refresh();
        // 1.000 g -> 5 poin, lalu 400 g -> 0 poin dengan sisa 400. Total tetap 5, bukan 10.
        $this->assertSame(5, $this->kelas->total_poin);
        $this->assertSame(400, $this->saldo($this->plastik)->sisa_gram);
        $this->assertSame('dikoreksi', $pertama->refresh()->status);
    }

    public function test_hapus_setoran_menghitung_ulang(): void
    {
        $this->setor([$this->item($this->plastik, '1', 'kg')]);
        $setoran = Setoran::first();

        $this->actingAs($this->admin)->delete("/admin/setoran/{$setoran->id}");

        $this->kelas->refresh();
        $this->assertSame(0, $this->kelas->total_poin);
        $this->assertSame(0, $this->kelas->total_gram);
        $this->assertNull($this->saldo($this->plastik));
        $this->assertSame(0, Setoran::count());
    }

    public function test_poin_tidak_bisa_dimanipulasi_dari_browser(): void
    {
        $this->actingAs($this->petugas)->post('/admin/setoran', [
            'nama_penyetor' => 'Budi',
            'kelas_id' => $this->kelas->id,
            'tanggal' => today()->toDateString(),
            'items' => [$this->item($this->plastik, '500') + ['poin' => 9999]],
            'poin' => 9999, // diabaikan
            'petugas_id' => $this->admin->id, // diabaikan
        ]);

        $this->assertSame(0, Setoran::first()->poin);
        $this->assertSame($this->petugas->id, Setoran::first()->petugas_id);
    }

    public function test_validasi_item(): void
    {
        $base = ['nama_penyetor' => 'Budi', 'kelas_id' => $this->kelas->id, 'tanggal' => today()->toDateString()];

        // tanpa item
        $this->actingAs($this->petugas)->post('/admin/setoran', $base + ['items' => []])->assertSessionHasErrors('items');

        // berat negatif dan jenis tidak ada
        $this->actingAs($this->petugas)->post('/admin/setoran', $base + ['items' => [
            ['jenis_sampah_id' => 9999, 'berat' => '-5', 'satuan' => 'kg'],
        ]])->assertSessionHasErrors(['items.0.jenis_sampah_id', 'items.0.berat']);

        // jenis ganda
        $this->actingAs($this->petugas)->post('/admin/setoran', $base + ['items' => [
            $this->item($this->plastik, '100'), $this->item($this->plastik, '200'),
        ]])->assertSessionHasErrors();

        $this->assertSame(0, Setoran::count());
    }

    public function test_bernilai_poin_hanya_untuk_kategori_anorganik(): void
    {
        $this->actingAs($this->admin)->post('/admin/jenis-sampah', [
            'nama' => 'Organik Palsu', 'kategori' => 'organik', 'bernilai_poin' => true,
            'gram_per_unit' => 1000, 'poin_per_unit' => 5,
        ])->assertSessionHasErrors('bernilai_poin');

        $this->assertDatabaseMissing('jenis_sampah', ['nama' => 'Organik Palsu']);
    }

    public function test_petugas_tidak_boleh_mengoreksi_atau_menghapus(): void
    {
        $this->setor([$this->item($this->plastik, '1', 'kg')]);
        $setoran = Setoran::first();

        $this->actingAs($this->petugas)->delete("/admin/setoran/{$setoran->id}")->assertForbidden();
    }

    public function test_siswa_tidak_boleh_masuk_halaman_admin_dan_tamu_diarahkan_ke_login(): void
    {
        $siswa = User::factory()->create(['role' => 'siswa', 'username' => 'siswa1']);

        $this->actingAs($siswa)->get('/admin')->assertForbidden();

        auth()->logout();
        $this->get('/admin')->assertRedirect('/masuk');
    }

    public function test_halaman_publik_dapat_dibuka(): void
    {
        $this->get('/')->assertOk();
        $this->get('/peringkat')->assertOk();
        $this->get('/riwayat')->assertOk();
    }
}
