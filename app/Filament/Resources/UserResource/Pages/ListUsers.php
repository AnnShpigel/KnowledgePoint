<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Страница списка пользователей административной панели.
 *
 * @package App\Filament\Resources\UserResource\Pages
 */
class ListUsers extends ListRecords
{
    /** @var string Связанный ресурс. */
    protected static string $resource = UserResource::class;

    /**
     * Действия в заголовке страницы.
     *
     * @return list<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить пользователя')];
    }
}
