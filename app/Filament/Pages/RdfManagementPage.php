<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Contents;
use App\Services\RdfService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

/**
 * Страница управления RDF-хранилищем Fuseki.
 *
 * Центр управления семантическим слоем системы: инициализация хранилища,
 * загрузка онтологии, массовая синхронизация одобренного контента,
 * мониторинг состояния Fuseki и экспорт данных.
 *
 * Все операции выполняются синхронно. Для синхронизации большого
 * числа записей рекомендуется использовать CLI:
 *   php artisan rdf:sync
 *
 * @package App\Filament\Pages
 */
class RdfManagementPage extends Page
{
    /** @var string Blade-шаблон страницы. */
    protected static string $view = 'filament.pages.rdf-management';

    /** @var string|null Иконка навигации. */
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    /** @var string|null Метка пункта навигации. */
    protected static ?string $navigationLabel = 'RDF-хранилище';

    /** @var string|null Группа навигации. */
    protected static ?string $navigationGroup = 'Семантика';

    /** @var string|null Заголовок страницы. */
    protected static ?string $title = 'Управление RDF-хранилищем';

    /** @var int|null Порядок в группе навигации. */
    protected static ?int $navigationSort = 2;

    /**
     * Статус подключения к Fuseki: 'online' | 'offline' | 'unknown'.
     *
     * @var string
     */
    public string $fusekiStatus = 'unknown';

    /**
     * Количество одобренных материалов (готовы к синхронизации).
     *
     * @var int
     */
    public int $approvedCount = 0;

    /**
     * Инициализировать страницу: проверить Fuseki и подготовить статистику.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->refreshStatus();
    }

    /**
     * Обновить статус Fuseki и счётчик одобренных материалов.
     *
     * @return void
     */
    public function refreshStatus(): void
    {
        try {
            $this->fusekiStatus = app(RdfService::class)->ping() ? 'online' : 'offline';
        } catch (\Throwable) {
            $this->fusekiStatus = 'offline';
        }

        $this->approvedCount = Contents::where('status', 'approved')->count();
    }

    /**
     * Кнопки действий в заголовке страницы.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh_status')
                ->label('Обновить статус')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action('refreshStatus'),

            Action::make('init_dataset')
                ->label('Инициализировать датасет')
                ->icon('heroicon-o-plus-circle')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Инициализация датасета Fuseki')
                ->modalDescription('Создаст датасет «knowledge» в Fuseki (если отсутствует) и загрузит онтологию. Безопасно запускать повторно.')
                ->action(function (): void {
                    try {
                        Artisan::call('rdf:sync', ['--init' => true]);
                        $this->refreshStatus();
                        Notification::make()
                            ->title('Датасет и онтология инициализированы')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Ошибка инициализации')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('upload_ontology')
                ->label('Загрузить онтологию')
                ->icon('heroicon-o-document-arrow-up')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Загрузит файл ontology/knowledge_aggregator.ttl в Fuseki. Существующие триплеты онтологии будут перезаписаны.')
                ->action(function (): void {
                    try {
                        app(RdfService::class)->uploadOntology();
                        Notification::make()
                            ->title('Онтология успешно загружена')
                            ->body('Файл ontology/knowledge_aggregator.ttl загружен в Fuseki')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Ошибка загрузки онтологии')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('sync_all')
                ->label('Синхронизировать всё')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Синхронизация контента с Fuseki')
                ->modalDescription(
                    "Будут синхронизированы все одобренные материалы ({$this->approvedCount} записей). "
                    . 'Операция может занять несколько минут. '
                    . 'Рекомендуется для больших датасетов использовать CLI: php artisan rdf:sync'
                )
                ->action(function (): void {
                    try {
                        Artisan::call('rdf:sync');
                        $this->refreshStatus();
                        Notification::make()
                            ->title('Синхронизация завершена')
                            ->body("Синхронизировано материалов: {$this->approvedCount}")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Ошибка синхронизации')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
