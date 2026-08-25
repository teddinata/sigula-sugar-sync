<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatusPenderes;
use App\Enums\StatusPetani;
use Database\Factories\PetaniFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Petani extends Model
{
    /** @use HasFactory<PetaniFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'petani';

    protected $fillable = [
        'nama',
        'kode_lahan',
        'rt_rw',
        'kontak',
        'alamat',
        'aktif',
    ];

    /**
     * Default kolom `aktif` ikut ditegaskan di sini: nilai default database
     * tidak terbawa ke instance hasil create(), sehingga tanpa ini response
     * pembuatan petani baru mengirim aktif=false.
     */
    protected $attributes = [
        'aktif' => true,
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
        ];
    }

    /** @return HasMany<Pembelian, $this> */
    public function pembelian(): HasMany
    {
        return $this->hasMany(Pembelian::class);
    }

    /**
     * Status penderes/pemilik lahan. Satu petani bisa punya lebih dari satu,
     * mis. PMS + PLMD — karena itu relasi, bukan kolom enum.
     *
     * @return HasMany<PetaniStatus, $this>
     */
    public function statusPenderes(): HasMany
    {
        return $this->hasMany(PetaniStatus::class);
    }

    /** @return array<int, StatusPenderes> */
    public function daftarStatusPenderes(): array
    {
        return $this->statusPenderes
            ->map(fn (PetaniStatus $s): StatusPenderes => $s->kode)
            // Urutan baris database tidak dijamin, jadi selalu diurutkan ulang.
            ->sortBy(fn (StatusPenderes $s): int => $s->urutan())
            ->values()
            ->all();
    }

    public function scopeCari(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('nama', 'like', '%'.$term.'%')
                ->orWhere('kode_lahan', 'like', '%'.$term.'%')
                ->orWhere('kontak', 'like', '%'.$term.'%');
        });
    }

    /** Menyaring petani berdasarkan satu atau beberapa status penderes. */
    public function scopeBerstatusPenderes(Builder $query, array $kode): Builder
    {
        return $kode === []
            ? $query
            : $query->whereHas('statusPenderes', fn (Builder $q) => $q->whereIn('kode', $kode));
    }

    /**
     * Kode lahan (mis. "BA-002") sekaligus berfungsi sebagai nomor member —
     * begitu aturan client — jadi statusnya disimpulkan, bukan disimpan.
     */
    public function isMember(): bool
    {
        return filled($this->kode_lahan);
    }

    public function status(): StatusPetani
    {
        return $this->isMember() ? StatusPetani::MEMBER : StatusPetani::NON_MEMBER;
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('aktif', true);
    }
}
