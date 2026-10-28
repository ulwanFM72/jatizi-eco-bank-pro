<?php

namespace App\Console\Commands;

use App\Services\SetoranService;
use Illuminate\Console\Command;

class HitungUlangPoin extends Command
{
    protected $signature = 'jatizi:hitung-ulang';

    protected $description = 'Hitung ulang poin dan sisa berat seluruh kelas dan akun dari data setoran';

    public function handle(SetoranService $service): int
    {
        $service->hitungUlangSemua();
        $this->info('Poin dan sisa berat berhasil dihitung ulang.');

        return self::SUCCESS;
    }
}
