<?php

declare(strict_types=1);

namespace App\Filament\Resources\ParseLogResource\Pages;

use App\Filament\Resources\ParseLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Страница списка логов парсинга.
 *
 * @package App\Filament\Resources\ParseLogResource\Pages
 */
class ListParseLogs extends ListRecords
{
    /** @var string Связанный ресурс. */
    protected static string $resource = ParseLogResource::class;

    /**
     * Заголовок страницы.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'История парсинга';
    }

    /**
     * Действия в заголовке — отсутствуют (ресурс только для чтения).
     *
     * @return list<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
