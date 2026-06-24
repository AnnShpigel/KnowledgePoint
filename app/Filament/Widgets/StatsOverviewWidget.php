<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Contents;
use App\Models\ParseLog;
use App\Models\Source;
use App\Models\User;
use App\Services\RdfService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Виджет сводной статистики для дашборда административной панели.
 *
 * Отображает ключевые метрики системы в виде информационных карточек:
 * количество материалов по статусам, активные источники, пользователи,
 * а также статус подключения к Fuseki.
 *
 * Обновляется каждые 30 секунд через Livewire polling.
 *
 * @package App\Filament\Widgets
 */
class StatsOverviewWidget extends BaseWidget
{
    /**
     * Интервал автообновления виджета (строка вида '30s', '1m').
     * Позволяет видеть изменения числа материалов в реальном времени
     * при работающем парсере.
     *
     * @var string|null
     */
    protected static ?string $pollingInterval = '30s';

    /**
     * Порядковый номер виджета на дашборде.
     *
     * @var int|null
     */
    protected static ?int $sort = 1;

    /**
     * Сформировать список карточек статистики.
     *
     * @return list<Stat> Массив объектов статистики для отображения на дашборде.
     */
    protected function getStats(): array
    {
        $totalContents = Contents::count();
        $pendingCount = Contents::where('status', 'pending')->count();
        $approvedCount = Contents::where('status', 'approved')->count();
        $rejectedCount = Contents::where('status', 'rejected')->count();

        $activeSources = Source::where('is_active', true)->count();
        $totalSources = Source::count();

        $totalUsers = User::count();
        $adminCount = User::where('role', 'admin')->count();

        // Последний успешный парсинг.
        // Используем ->first() вместо ->value(): last-й возвращает сырую строку из БД,
        // а не Carbon-объект, что вызывает ошибку при вызове diffForHumans().
        $lastSuccessLog = ParseLog::where('status', 'success')
            ->latest('finished_at')
            ->first();

        $lastSuccessLabel = $lastSuccessLog?->finished_at?->diffForHumans() ?? 'Никогда';

        // Проверка доступности Fuseki
        $fusekiOnline = false;
        try {
            $fusekiOnline = app(RdfService::class)->ping();
        } catch (\Throwable) {
            // Fuseki недоступен — не прерываем рендеринг дашборда
        }

        return [
            Stat::make('Материалов всего', $totalContents)
                ->description("Ожидают модерации: {$pendingCount}")
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingCount > 0 ? 'warning' : 'success')
                ->chart([$rejectedCount, $pendingCount, $approvedCount]),

            Stat::make('Одобрено', $approvedCount)
                ->description("Отклонено: {$rejectedCount}")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Источники', "{$activeSources}/{$totalSources}")
                ->description('Активных / всего')
                ->descriptionIcon('heroicon-m-server')
                ->color('info'),

            Stat::make('Пользователи', $totalUsers)
                ->description("Администраторов: {$adminCount}")
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Fuseki', $fusekiOnline ? 'Online' : 'Offline')
                ->description($fusekiOnline ? 'Хранилище RDF доступно' : 'Хранилище недоступно')
                ->descriptionIcon($fusekiOnline ? 'heroicon-m-signal' : 'heroicon-m-no-symbol')
                ->color($fusekiOnline ? 'success' : 'danger'),

            Stat::make('Последний парсинг', $lastSuccessLabel)
                ->description('Успешный запуск парсера')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('gray'),
        ];
    }
}
