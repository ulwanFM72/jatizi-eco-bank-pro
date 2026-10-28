<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetoranRequest;
use App\Models\JenisSampah;
use App\Models\Kelas;
use App\Models\Setoran;
use App\Services\SetoranService;
use App\Support\SetoranPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SetoranController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date'],
            'kelas_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $setoran = Setoran::query()
            ->with(['kelas:id,nama', 'items.jenis:id,nama,kategori', 'petugas:id,name'])
            ->filter($filters, cariNama: true)
            ->orderByDesc('setoran.tanggal')
            ->orderByDesc('setoran.id')
            ->paginate(15)->withQueryString()
            ->through(fn (Setoran $s) => SetoranPresenter::baris($s, 'staff'));

        return Inertia::render('Admin/Setoran/Index', [
            'setoran' => $setoran,
            'filters' => [
                'dari' => $filters['dari'] ?? '',
                'sampai' => $filters['sampai'] ?? '',
                'kelas_id' => $filters['kelas_id'] ?? '',
                'q' => $filters['q'] ?? '',
            ],
            'kelasOptions' => Kelas::orderBy('tipe')->orderBy('nama')->get(['id', 'nama', 'tipe']),
            'jenisOptions' => JenisSampah::where('aktif', true)
                ->orderBy('kategori')->orderBy('nama')
                ->get(['id', 'nama', 'kategori', 'bernilai_poin', 'gram_per_unit', 'poin_per_unit']),
            'bisaKoreksi' => $request->user()->isAdmin(),
        ]);
    }

    public function store(SetoranRequest $request, SetoranService $service)
    {
        $setoran = $service->catat($request->dataSetoran(), $request->user());

        if (! $setoran->wasRecentlyCreated) {
            return back()->with('success', 'Setoran ini sudah tersimpan sebelumnya, tidak dicatat dua kali.');
        }

        return back()->with('success', sprintf(
            'Setoran dari %s (%s) tersimpan: %d jenis sampah, total %s g. Poin diperoleh: %d.',
            $setoran->nama_penyetor,
            $setoran->kelas()->value('nama'),
            $setoran->items()->count(),
            number_format($setoran->berat_gram, 0, ',', '.'),
            $setoran->poin,
        ));
    }

    public function update(SetoranRequest $request, Setoran $setoran, SetoranService $service)
    {
        $service->koreksi($setoran, $request->dataSetoran());

        return back()->with('success', 'Setoran dikoreksi. Poin dan sisa berat dihitung ulang otomatis.');
    }

    public function destroy(Setoran $setoran, SetoranService $service)
    {
        $service->hapus($setoran);

        return back()->with('success', 'Setoran dihapus. Poin dan sisa berat dihitung ulang otomatis.');
    }
}
