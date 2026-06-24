<?php

declare(strict_types=1);

namespace App\Filament\Resources\SourceResource\Pages;

use App\Filament\Resources\SourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Страница редактирования источника данных.
 *
 * @package App\Filament\Resources\SourceResource\Pages
 */
class EditSource extends EditRecord
{
    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
