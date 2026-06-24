<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contents>
 */
class ContentsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'source_id' => \App\Models\Source::factory(),
            'title' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'author' => $this->faker->name,
            'type' => $this->faker->randomElement(['article', 'video', 'post']),
            'url' => $this->faker->url,
            'language' => $this->faker->randomElement(['en', 'ru']),
            'published_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'status' => 'approved',
            'tags' => $this->faker->words(3),
        ];
    }
}
