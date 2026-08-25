<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pengepul;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Pengepul> */
class PengepulFactory extends Factory
{
    protected $model = Pengepul::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nama' => 'Pengepul '.fake()->unique()->firstName(),
            'kontak' => fake()->numerify('08##########'),
            'alamat' => fake()->streetAddress(),
            'aktif' => true,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(fn (): array => ['aktif' => false]);
    }
}
