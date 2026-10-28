<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Buku besar per kelas/akun per jenis sampah. Hanya diubah oleh SetoranService. */
class SaldoJenis extends Model
{
    protected $table = 'saldo_jenis';

    protected $fillable = ['pemilik_tipe', 'pemilik_id', 'jenis_sampah_id', 'total_gram', 'sisa_gram', 'total_poin'];

    protected function casts(): array
    {
        return [
            'total_gram' => 'integer',
            'sisa_gram' => 'integer',
            'total_poin' => 'integer',
        ];
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisSampah::class, 'jenis_sampah_id');
    }
}
