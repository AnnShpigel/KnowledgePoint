<?php

declare(strict_types=1);

namespace App\Filament\Resources\SourceResource\Pages;

use App\Filament\Resources\SourceResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Страница создания нового источника данных.
 *
 * @package App\Filament\Resources\SourceResource\Pages
 */
class CreateSource extends CreateRecord
{
    protected static string $resource = SourceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
