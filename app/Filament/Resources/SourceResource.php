<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SourceResource\Pages;
use App\Models\ParseLog;
use App\Models\Source;
use App\Services\ParserFactory;
use Carbon\Carbon;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

/**
 * Ресурс управления источниками данных для агрегации.
 *
 * Предоставляет CRUD-интерфейс для настройки платформ-источников метаданных
 * (КиберЛенинка, Europeana и др.), а также возможность запуска парсера
 * прямо из административной панели без использования CLI.
 *
 * Особенности:
 *  - Форма адаптируется к типу источника (OAI-PMH показывает специфические поля)
 *  - Триггер парсинга выполняется синхронно (лимит 50 записей для UI)
 *  - Статус последнего парсинга отображается в таблице
 *
 * @package App\Filament\Resources
 */
class SourceResource extends Resource
{
    /** @var string|null Связанная модель Eloquent. */
    protected static ?string $model = Source::class;

    /** @var string|null Иконка Heroicons для навигации. */
    protected static ?string $navigationIcon = 'heroicon-o-server';

    /** @var string|null Метка пункта навигации. */
    protected static ?string $navigationLabel = 'Источники';

    /** @var string|null Группа навигации. */
    protected static ?string $navigationGroup = 'Контент';

    /** @var string|null Единственное число. */
    protected static ?string $modelLabel = 'Источник';

    /** @var string|null Множественное число. */
    protected static ?string $pluralModelLabel = 'Источники';

    /** @var int|null Порядок в группе навигации. */
    protected static ?int $navigationSort = 2;

    /**
     * Определить схему формы создания/редактирования источника.
     *
     * Форма разделена на секции: базовые данные, настройки API/OAI-PMH,
     * параметры парсера и управление расписанием.
     *
     * @param Form $form Объект формы Filament.
     *
     * @return Form Настроенная форма.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Основная информация')
                ->schema([
                    TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('slug')
                        ->label('Slug (URL-идентификатор)')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(100)
                        ->helperText('Используется в команде: php artisan source:parse {slug}'),

                    TextInput::make('description')
                        ->label('Описание')
                        ->maxLength(500)
                        ->columnSpanFull(),

                    TextInput::make('url')
                        ->label('Базовый URL платформы')
                        ->required()
                        ->url()
                        ->maxLength(255)
                        ->placeholder('https://cyberleninka.ru'),
                ])
                ->columns(2),

            Section::make('Протокол доступа')
                ->schema([
                    Select::make('type')
                        ->label('Тип источника')
                        ->options([
                            'oai-pmh' => 'OAI-PMH 2.0 (протокол репозиториев)',
                            'api' => 'REST API',
                            'scraper' => 'Web-скрапинг',
                        ])
                        ->required()
                        ->default('oai-pmh')
                        ->live(),

                    TextInput::make('api_endpoint')
                        ->label('Endpoint API / OAI-PMH URL')
                        ->url()
                        ->maxLength(255)
                        ->placeholder('https://cyberleninka.ru/oai')
                        ->helperText('OAI-PMH: URL сервиса. REST API: базовый URL для запросов.'),

                    TextInput::make('api_key')
                        ->label('API-ключ')
                        ->password()
                        ->revealable()
                        ->maxLength(500)
                        ->visible(fn(Get $get): bool => $get('type') === 'api')
                        ->helperText('Оставьте пустым, если API не требует ключа.'),
                ])
                ->columns(2),

            Section::make('Настройки парсера OAI-PMH')
                ->description('Конфигурация автоматического обхода источника')
                ->schema([
                    TextInput::make('parse_settings.oai_endpoint')
                        ->label('OAI-PMH Endpoint')
                        ->url()
                        ->placeholder('https://cyberleninka.ru/oai'),

                    TextInput::make('parse_settings.metadata_prefix')
                        ->label('Метадата-префикс')
                        ->default('oai_dc')
                        ->maxLength(50),

                    TextInput::make('parse_settings.oai_set')
                        ->label('Набор (set)')
                        ->maxLength(255)
                        ->helperText('Оставьте пустым для загрузки всех записей.'),

                    TextInput::make('parse_settings.batch_limit')
                        ->label('Лимит записей (0 = без ограничений)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),

                    Toggle::make('parse_settings.scrape_pages')
                        ->label('Скрапировать страницы для получения аннотаций')
                        ->default(true),

                    TextInput::make('parse_settings.scrape_delay_ms')
                        ->label('Задержка между скрапингом (мс)')
                        ->numeric()
                        ->minValue(0)
                        ->default(800),

                    TextInput::make('parse_settings.request_delay_ms')
                        ->label('Задержка между OAI-PMH запросами (мс)')
                        ->numeric()
                        ->minValue(0)
                        ->default(500),
                ])
                ->columns(2)
                ->visible(fn(Get $get): bool => $get('type') === 'oai-pmh')
                ->collapsed(),

            Section::make('Расписание')
                ->schema([
                    Toggle::make('is_active')
                        ->label('Источник активен')
                        ->default(true)
                        ->helperText('Неактивные источники не обрабатываются командой source:parse (без аргументов).'),

                    TextInput::make('parse_interval')
                        ->label('Интервал парсинга (секунды)')
                        ->numeric()
                        ->minValue(3600)
                        ->default(86400)
                        ->helperText('86400 = 1 сутки. Используется для планировщика задач.'),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Определить схему таблицы списка источников.
     *
     * @param Table $table Объект таблицы Filament.
     *
     * @return Table Настроенная таблица.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->description(fn(Source $record): string => $record->url),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'oai-pmh' => 'info',
                        'api' => 'success',
                        'scraper' => 'warning',
                        default => 'gray',
                    }),

                ToggleColumn::make('is_active')
                    ->label('Активен'),

                TextColumn::make('last_parsed_at')
                    ->label('Последний парсинг')
                    ->since()
                    ->sortable()
                    ->placeholder('Никогда')
                    ->tooltip(fn(Source $record): string => $record->last_parsed_at?->format('d.m.Y H:i:s') ?? ''),

                // Статус последнего лога
                TextColumn::make('latestParseLog.status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'success' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'success' => 'Успех',
                        'running' => 'Выполняется',
                        'failed' => 'Ошибка',
                        default => '—',
                    }),

                TextColumn::make('latestParseLog.items_parsed')
                    ->label('Записей')
                    ->numeric()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'oai-pmh' => 'OAI-PMH',
                        'api' => 'API',
                        'scraper' => 'Scraper',
                    ]),

                SelectFilter::make('is_active')
                    ->label('Статус')
                    ->options([
                        '1' => 'Активен',
                        '0' => 'Неактивен',
                    ]),
            ])
            ->actions([
                Action::make('parse')
                    ->label('Запустить')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Запуск парсинга')
                    ->modalDescription(
                        'Будет выполнен синхронный парсинг до 50 записей без скрапинга страниц. '
                            . 'Для полного импорта используйте CLI: php artisan source:parse {slug}'
                    )
                    ->visible(fn(Source $record): bool => app(ParserFactory::class)->supports($record))
                    ->action(function (Source $record): void {
                        // Проверяем, не запущен ли уже парсинг
                        if ($record->isParsingNow()) {
                            Notification::make()
                                ->title('Парсинг уже выполняется')
                                ->warning()
                                ->send();
                            return;
                        }

                        try {
                            Artisan::call('source:parse', [
                                'source' => (string) $record->id,
                                '--limit' => '50',
                                '--skip-scrape' => true,
                            ]);

                            $log = ParseLog::where('source_id', $record->id)
                                ->latest('started_at')
                                ->first();

                            $message = $log
                                ? "Создано: {$log->items_new}, обновлено: {$log->items_updated}"
                                : 'Запрос выполнен';

                            Notification::make()
                                ->title("Парсинг «{$record->name}» завершён")
                                ->body($message)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Ошибка парсинга')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    /**
     * Получить массив страниц ресурса.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSources::route('/'),
            'create' => Pages\CreateSource::route('/create'),
            'edit' => Pages\EditSource::route('/{record}/edit'),
        ];
    }
}
