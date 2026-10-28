<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JenisSampah extends Model
{
    protected $table = 'jenis_sampah';

    public const KATEGORI = ['organik', 'anorganik', 'b3'];

    protected $fillable = [
        'nama', 'kategori', 'keterangan', 'aktif', 'bernilai_poin', 'gram_per_unit', 'poin_per_unit',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'bernilai_poin' => 'boolean',
            'gram_per_unit' => 'integer',
            'poin_per_unit' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SetoranItem::class, 'jenis_sampah_id');
    }

    /** Jenis ini menghasilkan poin hanya bila ditandai bernilai poin dan aturannya lengkap. */
    public function punyaAturanPoin(): bool
    {
        return $this->bernilai_poin && $this->gram_per_unit > 0 && $this->poin_per_unit !== null;
    }
}
