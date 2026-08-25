<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Petani;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Petani> */
class PetaniFactory extends Factory
{
    protected $model = Petani::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nama' => $this->faker->name(),
            // Kode lahan sekaligus menandai petani sebagai member.
            'kode_lahan' => sprintf('BA-%03d', $this->faker->unique()->numberBetween(1, 999)),
            'rt_rw' => sprintf('%02d/%02d', $this->faker->numberBetween(1, 7), $this->faker->numberBetween(1, 3)),
            'aktif' => true,
            'kontak' => '08'.$this->faker->numerify('##-####-####'),
            'alamat' => $this->faker->address(),
        ];
    }

    /** Tanpa kode lahan = Non-Member. */
    public function nonMember(): static
    {
        return $this->state(fn (): array => ['kode_lahan' => null]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn (): array => ['aktif' => false]);
    }
}
