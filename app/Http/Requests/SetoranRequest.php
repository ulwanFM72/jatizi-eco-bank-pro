<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi setoran (dipakai untuk catat dan koreksi). Satu setoran berisi beberapa item
 * (jenis sampah + berat). Konversi kg ke gram dilakukan di sini, di server.
 */
class SetoranRequest extends FormRequest
{
    public const MAKS_GRAM = 500000; // 500 kg per jenis sampah

    public function authorize(): bool
    {
        return (bool) $this->user()?->isStaff();
    }

    public function rules(): array
    {
        return [
            'penyetor_id' => ['nullable', 'integer', 'exists:users,id'],
            'nama_penyetor' => ['required_without:penyetor_id', 'nullable', 'string', 'max:100'],
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid'],

            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.jenis_sampah_id' => ['required', 'integer', 'distinct', Rule::exists('jenis_sampah', 'id')->where('aktif', true)],
            'items.*.berat' => ['required', 'numeric', 'min:0.001'],
            'items.*.satuan' => ['required', Rule::in(['gram', 'kg'])],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Pilih minimal satu jenis sampah dan isi beratnya.',
            'items.min' => 'Pilih minimal satu jenis sampah dan isi beratnya.',
            'items.*.jenis_sampah_id.distinct' => 'Satu jenis sampah hanya boleh dipilih sekali dalam satu setoran.',
        ];
    }

    public function attributes(): array
    {
        return [
            'penyetor_id' => 'Akun penyetor',
            'nama_penyetor' => 'Nama penyetor',
            'kelas_id' => 'Kelas',
            'tanggal' => 'Tanggal',
            'catatan' => 'Catatan',
            'items' => 'Jenis sampah',
            'items.*.jenis_sampah_id' => 'Jenis sampah',
            'items.*.berat' => 'Berat',
            'items.*.satuan' => 'Satuan',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('items', []) as $i => $item) {
                    if ($validator->errors()->has("items.$i.berat") || $validator->errors()->has("items.$i.satuan")) {
                        continue;
                    }

                    $berat = trim((string) ($item['berat'] ?? ''));
                    $satuan = $item['satuan'] ?? 'kg';

                    if ($satuan === 'gram') {
                        if (! preg_match('/^\d+$/', $berat)) {
                            $validator->errors()->add("items.$i.berat", 'Berat dalam gram harus bilangan bulat.');
                            continue;
                        }
                    } elseif (! preg_match('/^\d+(\.\d{1,3})?$/', $berat)) {
                        $validator->errors()->add("items.$i.berat", 'Berat dalam kg maksimal 3 angka di belakang titik desimal.');
                        continue;
                    }

                    $gram = self::hitungGram($berat, $satuan);
                    if ($gram < 1) {
                        $validator->errors()->add("items.$i.berat", 'Berat minimal 1 gram.');
                    } elseif ($gram > self::MAKS_GRAM) {
                        $validator->errors()->add("items.$i.berat", 'Berat per jenis sampah maksimal 500 kg.');
                    }
                }
            },
        ];
    }

    /** Berat dalam gram (bilangan bulat) dihitung di server dari input kg atau gram. */
    public static function hitungGram(string $berat, string $satuan): int
    {
        return $satuan === 'kg'
            ? (int) round(((float) $berat) * 1000)
            : (int) $berat;
    }

    /** Data bersih untuk SetoranService. */
    public function dataSetoran(): array
    {
        $v = $this->validated();

        return [
            'penyetor_id' => $v['penyetor_id'] ?? null,
            'nama_penyetor' => $v['nama_penyetor'] ?? null,
            'kelas_id' => $v['kelas_id'] ?? null,
            'items' => array_map(fn ($it) => [
                'jenis_sampah_id' => (int) $it['jenis_sampah_id'],
                'berat_gram' => self::hitungGram(trim((string) $it['berat']), $it['satuan']),
            ], $v['items']),
            'tanggal' => $v['tanggal'],
            'catatan' => $v['catatan'] ?? null,
            'idempotency_key' => $v['idempotency_key'] ?? null,
        ];
    }
}
