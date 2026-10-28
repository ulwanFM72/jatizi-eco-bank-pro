<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JenisSampah;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class JenisSampahController extends Controller
{
    public function index()
    {
        $jenis = JenisSampah::query()
            ->withCount('items')
            ->orderBy('kategori')->orderBy('nama')
            ->get(['id', 'nama', 'kategori', 'keterangan', 'aktif', 'bernilai_poin', 'gram_per_unit', 'poin_per_unit']);

        return Inertia::render('Admin/JenisSampah/Index', ['jenis' => $jenis]);
    }

    public function store(Request $request)
    {
        JenisSampah::create($this->validasi($request));

        return back()->with('success', 'Jenis sampah ditambahkan.');
    }

    public function update(Request $request, JenisSampah $jenisSampah)
    {
        $jenisSampah->update($this->validasi($request, $jenisSampah));

        return back()->with('success', 'Jenis sampah diperbarui. Perubahan aturan poin hanya berlaku untuk setoran baru.');
    }

    public function destroy(JenisSampah $jenisSampah)
    {
        if ($jenisSampah->items()->exists()) {
            return back()->with('error', 'Jenis sampah sudah dipakai pada setoran. Nonaktifkan saja agar tidak muncul di form.');
        }

        $jenisSampah->delete();

        return back()->with('success', 'Jenis sampah dihapus.');
    }

    /** Hanya sampah an-organik daur ulang yang boleh bernilai poin; aturannya (gram dan poin) wajib diisi. */
    private function validasi(Request $request, ?JenisSampah $jenis = null): array
    {
        $unik = Rule::unique('jenis_sampah', 'nama');
        if ($jenis) {
            $unik = $unik->ignore($jenis->id);
        }
        $bernilai = fn () => $request->boolean('bernilai_poin');

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:80', $unik],
            'kategori' => ['required', Rule::in(JenisSampah::KATEGORI)],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'gram_per_unit' => [Rule::requiredIf($bernilai), 'nullable', 'integer', 'min:1', 'max:100000'],
            'poin_per_unit' => [Rule::requiredIf($bernilai), 'nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        if ($bernilai() && $data['kategori'] !== 'anorganik') {
            throw ValidationException::withMessages([
                'bernilai_poin' => 'Hanya sampah an-organik daur ulang yang dapat bernilai poin.',
            ]);
        }

        $data['aktif'] = $request->boolean('aktif', true);
        $data['bernilai_poin'] = $bernilai();

        if (! $data['bernilai_poin']) {
            $data['gram_per_unit'] = null;
            $data['poin_per_unit'] = null;
        }

        return $data;
    }
}
