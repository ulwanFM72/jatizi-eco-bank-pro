<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Setoran;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaporanController extends Controller
{
    private const MAKS_BARIS_PDF = 2000;

    public function index(Request $request)
    {
        $f = $this->filter($request);

        return Inertia::render('Admin/Laporan', [
            'filters' => $f,
            'kelasOptions' => Kelas::orderBy('tipe')->orderBy('nama')->get(['id', 'nama']),
        ] + $this->data($f));
    }

    public function pdf(Request $request)
    {
        $f = $this->filter($request);
        $data = $this->data($f);

        $detail = Setoran::query()
            ->with(['kelas:id,nama', 'items.jenis:id,nama,kategori', 'petugas:id,name'])
            ->filter($f, cariNama: false)
            ->orderBy('setoran.tanggal')->orderBy('setoran.id')
            ->limit(self::MAKS_BARIS_PDF)
            ->get();

        $pdf = Pdf::loadView('reports.setoran', $data + [
            'filters' => $f,
            'namaKelas' => $f['kelas_id'] ? Kelas::whereKey($f['kelas_id'])->value('nama') : 'Semua kelas',
            'detail' => $detail,
            'dipotong' => $data['ringkasan']['jumlah_transaksi'] > self::MAKS_BARIS_PDF,
            'dicetakOleh' => $request->user()->name,
            'maksBaris' => self::MAKS_BARIS_PDF,
        ])->setPaper('a4', 'portrait');

        $nama = "laporan-setoran-{$f['dari']}_sd_{$f['sampai']}.pdf";

        // ?unduh=1 mengunduh file; tanpa itu PDF dibuka di tab baru (siap dicetak).
        return $request->boolean('unduh') ? $pdf->download($nama) : $pdf->stream($nama);
    }

    /** @return array{dari:string,sampai:string,kelas_id:?int} */
    private function filter(Request $request): array
    {
        $v = $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
        ]);

        return [
            'dari' => $v['dari'] ?? now()->startOfMonth()->toDateString(),
            'sampai' => $v['sampai'] ?? now()->toDateString(),
            'kelas_id' => isset($v['kelas_id']) ? (int) $v['kelas_id'] : null,
        ];
    }

    private function data(array $f): array
    {
        $r = Setoran::query()->filter($f)
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(setoran.berat_gram), 0) as gram, COALESCE(SUM(setoran.poin), 0) as poin')
            ->first();

        $perKelas = Setoran::query()->filter($f)
            ->join('kelas', 'kelas.id', '=', 'setoran.kelas_id')
            ->groupBy('kelas.id', 'kelas.nama')
            ->selectRaw('kelas.id as id, kelas.nama as nama, COUNT(*) as jumlah, SUM(setoran.berat_gram) as gram, SUM(setoran.poin) as poin')
            ->orderByDesc('poin')->orderByDesc('gram')->orderBy('kelas.nama')
            ->get()
            ->map(fn ($k) => [
                'id' => $k->id,
                'nama' => $k->nama,
                'jumlah' => (int) $k->jumlah,
                'gram' => (int) $k->gram,
                'poin' => (int) $k->poin,
            ])->all();

        $perJenis = Setoran::query()->filter($f)
            ->join('setoran_item', 'setoran_item.setoran_id', '=', 'setoran.id')
            ->join('jenis_sampah', 'jenis_sampah.id', '=', 'setoran_item.jenis_sampah_id')
            ->groupBy('jenis_sampah.id', 'jenis_sampah.nama', 'jenis_sampah.kategori')
            ->selectRaw('jenis_sampah.id as id, jenis_sampah.nama as nama, jenis_sampah.kategori as kategori, COUNT(DISTINCT setoran.id) as jumlah, SUM(setoran_item.berat_gram) as gram, SUM(setoran_item.poin) as poin')
            ->orderBy('jenis_sampah.kategori')->orderBy('jenis_sampah.nama')
            ->get()
            ->map(fn ($j) => [
                'id' => $j->id,
                'nama' => $j->nama,
                'kategori' => $j->kategori,
                'jumlah' => (int) $j->jumlah,
                'gram' => (int) $j->gram,
                'poin' => (int) $j->poin,
            ])->all();

        $kategori = collect($perJenis)->groupBy('kategori');

        return [
            'ringkasan' => [
                'jumlah_transaksi' => (int) $r->jumlah,
                'total_gram' => (int) $r->gram,
                'total_poin' => (int) $r->poin,
            ],
            'perKelas' => $perKelas,
            'perJenis' => $perJenis,
            // Jumlah transaksi per kategori dihitung dari rincian per jenis (satu transaksi dapat memuat beberapa jenis).
            'perKategori' => collect(['organik', 'anorganik', 'b3'])->map(fn ($k) => [
                'kategori' => $k,
                'gram' => (int) $kategori->get($k, collect())->sum('gram'),
                'poin' => (int) $kategori->get($k, collect())->sum('poin'),
            ])->all(),
        ];
    }
}
