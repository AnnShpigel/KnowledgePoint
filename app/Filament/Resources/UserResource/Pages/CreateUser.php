<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Страница создания нового пользователя.
 *
 * @package App\Filament\Resources\UserResource\Pages
 */
class CreateUser extends CreateRecord
{
    /** @var string Связанный ресурс. */
    protected static string $resource = UserResource::class;

    /**
     * URL для перенаправления после успешного создания.
     *
     * @return string
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
