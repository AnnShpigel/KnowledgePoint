<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentsResource\Pages;

use App\Filament\Resources\ContentsResource;
use App\Services\RdfService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Страница редактирования метаданных материала.
 *
 * При сохранении автоматически синхронизирует изменения с Fuseki:
 *  - Одобренные материалы обновляются в RDF-графе
 *  - При смене статуса на rejected — удаляются из графа
 *
 * @package App\Filament\Resources\ContentsResource\Pages
 */
class EditContent extends EditRecord
{
    /** @var string Связанный ресурс. */
    protected static string $resource = ContentsResource::class;

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
     * Выполнить синхронизацию с Fuseki после сохранения формы.
     *
     * Метод вызывается автоматически Filament'ом после успешного сохранения.
     *
     * @return void
     */
    protected function afterSave(): void
    {
        $record = $this->record->fresh();
        $rdf = app(RdfService::class);

        try {
            if ($record->status === 'approved') {
                $rdf->syncContent($record);
            } else {
                $rdf->removeContent($record);
            }
        } catch (\Throwable) {
            // Fuseki временно недоступен — изменения в MySQL сохранены
        }
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
