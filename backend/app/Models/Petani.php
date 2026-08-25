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
        'status',
        'nomor_member',
        'kode_lahan',
        'rt_rw',
        'kontak',
        'alamat',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusPetani::class,
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
                ->orWhere('nomor_member', 'like', '%'.$term.'%')
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

    public function isMember(): bool
    {
        return $this->status === StatusPetani::MEMBER;
    }
}
