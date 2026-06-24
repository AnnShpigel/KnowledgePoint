<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentsResource\Pages;

use App\Filament\Resources\ContentsResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;

/**
 * Страница списка материалов с вкладками по статусу модерации.
 *
 * @package App\Filament\Resources\ContentsResource\Pages
 */
class ListContents extends ListRecords
{
    /** @var string Связанный ресурс. */
    protected static string $resource = ContentsResource::class;

    /**
     * Вкладки для быстрой фильтрации по статусу модерации.
     *
     * @return list<Tab>
     */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('На проверке')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', 'pending'))
                ->badge(\App\Models\Contents::where('status', 'pending')->count())
                ->badgeColor('warning'),

            'approved' => Tab::make('Одобренные')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', 'approved'))
                ->badge(\App\Models\Contents::where('status', 'approved')->count())
                ->badgeColor('success'),

            'rejected' => Tab::make('Отклонённые')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', 'rejected'))
                ->badge(\App\Models\Contents::where('status', 'rejected')->count())
                ->badgeColor('danger'),

            'all' => Tab::make('Все'),
        ];
    }

    /**
     * Вкладка по умолчанию.
     *
     * @return string
     */
    public function getDefaultActiveTab(): string | int | null
    {
        return 'pending';
    }

    /**
     * Действия в заголовке страницы.
     *
     * @return list<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Определяет максимальную ширину контента для текущей страницы списка.
     * 
     * @return MaxWidth|string|null Возвращает объект MaxWidth (Enum), строку (например, '7xl') или null.
     */
    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::Full;
    }
}
