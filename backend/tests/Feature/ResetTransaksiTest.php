<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\KategoriStok;
use App\Enums\Role;
use App\Models\Karyawan;
use App\Models\Pengepul;
use App\Models\Petani;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetTransaksiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMaster();
        $this->travelTo('2026-08-13 09:00:00');
    }

    private function isiTransaksi(): void
    {
        $this->masukSebagai(Role::OWNER);
        $petani = $this->petani('Sukirman');
        Pengepul::factory()->create();

        $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13', 'petaniId' => $petani->id, 'grade' => 'NS 1', 'kg' => 250,
        ])->assertCreated();

        $this->postJson('/api/v1/keuangan/biaya', [
            'tanggal' => '2026-08-13', 'kategori' => 'Transport',
            'keterangan' => 'Solar', 'jumlah' => 500000,
        ])->assertCreated();
    }

    public function test_transaksi_dikosongkan_master_data_dipertahankan(): void
    {
        $this->isiTransaksi();

        $petaniSebelum = Petani::query()->count();
        $karyawanSebelum = Karyawan::query()->count();
        $userSebelum = User::query()->count();

        $this->artisan('sigula:reset-transaksi', ['--force' => true, '--tanpa-backup' => true])
            ->assertSuccessful();

        // Transaksi bersih.
        foreach (['pembelian', 'kartu_stok', 'biaya_operasional', 'audit_logs', 'nomor_urut'] as $tabel) {
            $this->assertSame(0, DB::table($tabel)->count(), "Tabel {$tabel} seharusnya kosong.");
        }

        // Master data utuh.
        $this->assertSame($petaniSebelum, Petani::query()->count());
        $this->assertSame($karyawanSebelum, Karyawan::query()->count());
        $this->assertSame($userSebelum, User::query()->count());
        $this->assertSame(1, Pengepul::query()->count());
        $this->assertGreaterThan(0, DB::table('grade_harga')->count());
        $this->assertGreaterThan(0, DB::table('tarif_upah')->count());
    }

    public function test_saldo_stok_dinolkan_bukan_dihapus(): void
    {
        $this->isiTransaksi();
        $this->assertSame(250.0, $this->saldo(KategoriStok::NS1));

        $this->artisan('sigula:reset-transaksi', ['--force' => true, '--tanpa-backup' => true])
            ->assertSuccessful();

        $this->assertSame(0.0, $this->saldo(KategoriStok::NS1));
        // Barisnya tetap ada supaya jadi titik awal stok opname pertama.
        $this->assertGreaterThan(0, DB::table('stok_saldo')->count());
    }

    public function test_penomoran_kwitansi_mulai_dari_awal_lagi(): void
    {
        $this->isiTransaksi();
        $this->artisan('sigula:reset-transaksi', ['--force' => true, '--tanpa-backup' => true])->assertSuccessful();

        $this->masukSebagai(Role::OWNER);
        $nomor = $this->postJson('/api/v1/pembelian', [
            'tanggal' => '2026-08-13', 'petaniId' => Petani::query()->value('id'),
            'grade' => 'NS 1', 'kg' => 10,
        ])->assertCreated()->json('data.nomorKwitansi');

        $this->assertStringEndsWith('0001', $nomor);
    }

    public function test_bisa_dibatalkan_lewat_konfirmasi(): void
    {
        $this->isiTransaksi();
        $sebelum = DB::table('pembelian')->count();

        $this->artisan('sigula:reset-transaksi')
            ->expectsConfirmation('Kosongkan tabel transaksi di atas?', 'no')
            ->assertSuccessful();

        $this->assertSame($sebelum, DB::table('pembelian')->count());
    }
}
