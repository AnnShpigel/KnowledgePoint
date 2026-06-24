<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\RdfManagementPage;
use App\Filament\Pages\SparqlQueryPage;
use App\Filament\Resources\ContentsResource;
use App\Filament\Resources\ParseLogResource;
use App\Filament\Resources\SourceResource;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\StatsOverviewWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Провайдер административной панели Filament.
 *
 * Настраивает внешний вид, маршруты, ресурсы, виджеты и группы навигации
 * панели управления системой агрегации знаний.
 *
 * Панель доступна по адресу /admin.
 * Доступ разрешён только пользователям с ролью 'admin' и флагом is_active = true.
 *
 * Структура навигации:
 *  - Контент: Источники, Материалы (модерация)
 *  - Семантика: SPARQL-запросы, RDF-хранилище
 *  - Система: Пользователи, Логи парсинга
 *
 * @package App\Providers\Filament
 */
class AdminPanelProvider extends PanelProvider
{
    /**
     * Настроить и вернуть конфигурацию Filament-панели.
     *
     * @param Panel $panel Экземпляр панели для конфигурирования.
     *
     * @return Panel Настроенная панель.
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Агрегатор знаний')
            ->favicon(asset('images/logo.php'))

            // Цветовая схема: индиго — профессиональный, академический тон
            ->colors(['primary' => Color::Indigo])

            // Тёмная тема следует системным настройкам браузера
            ->darkMode(true)

            // Автоматически обнаруживать ресурсы, страницы и виджеты
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')

            // Явная регистрация виджетов на дашборде
            ->widgets([
                AccountWidget::class,
                StatsOverviewWidget::class,
            ])

            // Группы навигации БЕЗ иконок: в Filament v3 нельзя иметь
            // иконку одновременно у группы и у её пунктов меню.
            // Иконки заданы на уровне ресурсов/страниц — этого достаточно.
            ->navigationGroups([
                NavigationGroup::make('Контент'),
                NavigationGroup::make('Семантика'),
                NavigationGroup::make('Система')
                    ->collapsed(),
            ])

            // Middleware стек для сессионной аутентификации
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
