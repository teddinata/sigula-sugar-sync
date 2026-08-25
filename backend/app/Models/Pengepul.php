<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Perantara antara petani dan perusahaan pada transaksi pembelian bahan. */
class Pengepul extends Model
{
    /** @use HasFactory<\Database\Factories\PengepulFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pengepul';

    protected $fillable = ['nama', 'kontak', 'alamat', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    /** @return HasMany<Pembelian, $this> */
    public function pembelian(): HasMany
    {
        return $this->hasMany(Pembelian::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('aktif', true);
    }

    public function scopeCari(Builder $query, ?string $term): Builder
    {
        return blank($term) ? $query : $query->where('nama', 'like', '%'.$term.'%');
    }
}
