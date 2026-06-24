<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Страница редактирования пользователя.
 *
 * @package App\Filament\Resources\UserResource\Pages
 */
class EditUser extends EditRecord
{
    /** @var string Связанный ресурс. */
    protected static string $resource = UserResource::class;

    /**
     * Действия в заголовке страницы редактирования.
     *
     * @return list<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * URL для перенаправления после сохранения.
     *
     * @return string
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
