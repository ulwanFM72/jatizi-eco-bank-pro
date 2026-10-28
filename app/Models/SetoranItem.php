<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetoranItem extends Model
{
    protected $table = 'setoran_item';

    /** `poin` dan `poin_pribadi` sengaja tidak fillable: hanya SetoranService yang menghitungnya. */
    protected $fillable = ['setoran_id', 'jenis_sampah_id', 'berat_gram', 'aturan_gram', 'aturan_poin'];

    protected function casts(): array
    {
        return [
            'berat_gram' => 'integer',
            'aturan_gram' => 'integer',
            'aturan_poin' => 'integer',
            'poin' => 'integer',
            'poin_pribadi' => 'integer',
        ];
    }

    public function setoran(): BelongsTo
    {
        return $this->belongsTo(Setoran::class);
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisSampah::class, 'jenis_sampah_id');
    }
}
