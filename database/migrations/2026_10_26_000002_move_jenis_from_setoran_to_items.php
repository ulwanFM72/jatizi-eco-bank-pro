<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Setiap setoran lama (termasuk yang sudah dihapus lunak) menjadi satu transaksi dengan satu item.
        DB::table('setoran_item')->insertUsing(
            ['setoran_id', 'jenis_sampah_id', 'berat_gram', 'aturan_gram', 'aturan_poin', 'poin', 'poin_pribadi', 'created_at', 'updated_at'],
            DB::table('setoran')->select(
                'id', 'jenis_sampah_id', 'berat_gram', 'aturan_gram', 'aturan_poin', 'poin', 'poin_pribadi', 'created_at', 'updated_at'
            )
        );

        // 2. Jenis yang tidak bernilai poin (organik, B3, an-organik non daur ulang) tidak lagi menghasilkan poin.
        DB::table('setoran_item')
            ->whereIn('jenis_sampah_id', DB::table('jenis_sampah')->where('bernilai_poin', false)->select('id'))
            ->update(['aturan_gram' => null, 'aturan_poin' => null]);

        // 3. Kolom jenis dan aturan pada setoran dipindah ke item. `berat_gram`, `poin`, `poin_pribadi`
        //    pada setoran kini berarti total seluruh item.
        Schema::table('setoran', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jenis_sampah_id');
            $table->dropColumn(['aturan_gram', 'aturan_poin']);
        });

        // Setelah migrasi, jalankan:  php artisan jatizi:hitung-ulang
        // agar poin dan sisa berat seluruh kelas/akun dihitung ulang dengan aturan baru.
    }

    public function down(): void
    {
        Schema::table('setoran', function (Blueprint $table) {
            $table->foreignId('jenis_sampah_id')->nullable()->constrained('jenis_sampah')->restrictOnDelete();
            $table->unsignedInteger('aturan_gram')->default(1000);
            $table->unsignedInteger('aturan_poin')->default(5);
        });
        // Catatan: rincian lebih dari satu jenis tidak dapat dikembalikan ke satu kolom.
    }
};
