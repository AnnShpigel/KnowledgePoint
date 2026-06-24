<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Contents;
use App\Models\Source;
use Illuminate\Support\Str;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $sources = Source::all();
        $types = ['article', 'video', 'course', 'documentation', 'book'];
        $languages = ['ru', 'en'];

        // Создаём 48 тестовых материалов
        for ($i = 1; $i <= 48; $i++) {
            $source = $sources->random();
            $type = $types[array_rand($types)];

            Contents::create([
                'source_id' => $source->id,
                'title' => "Материал №{$i}: " . $this->getTitleByType($type),
                'description' => "Краткое описание материала №{$i}. Содержит ключевые идеи, методологию и выводы. Подходит для студентов и исследователей.",
                'author' => $this->getRandomAuthor(),
                'type' => $type,
                'url' => "https://example.com/{$type}/{$i}",
                'language' => $languages[array_rand($languages)],
                'published_at' => now()->subDays(rand(1, 1000)),
                'status' => 'approved',
                'tags' => json_encode(['образование', 'наука', 'технологии']),
                'views' => rand(0, 5000),
            ]);
        }
    }

    private function getTitleByType(string $type): string
    {
        return match($type) {
            'article' => 'Научный анализ',
            'video' => 'Лекция',
            'course' => 'Обучающий курс',
            'documentation' => 'Техническая документация',
            'book' => 'Электронная книга',
        };
    }

    private function getRandomAuthor(): string
    {
        $authors = ['John Doe', 'Jane Smith', 'Alex Johnson', 'Maria Garcia', 'David Brown'];
        return $authors[array_rand($authors)];
    }
}
