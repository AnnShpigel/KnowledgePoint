<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ParseLogResource\Pages;
use App\Models\ParseLog;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Ресурс просмотра логов парсинга источников данных.
 *
 * Только для чтения — создание и редактирование логов через UI не предусмотрено,
 * записи создаются автоматически при каждом запуске парсера (CLI или API).
 *
 * Отображаемая информация:
 *  - Источник, статус, временны́е метки
 *  - Детальная статистика: новые/обновлённые/пропущенные записи
 *  - Продолжительность выполнения
 *  - Сообщение об ошибке (при статусе 'failed')
 *
 * @package App\Filament\Resources
 */
class ParseLogResource extends Resource
{
    /** @var string|null Связанная модель Eloquent. */
    protected static ?string $model = ParseLog::class;

    /** @var string|null Иконка навигации. */
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    /** @var string|null Метка пункта навигации. */
    protected static ?string $navigationLabel = 'Логи парсинга';

    /** @var string|null Группа навигации. */
    protected static ?string $navigationGroup = 'Система';

    /** @var string|null Единственное число. */
    protected static ?string $modelLabel = 'Лог парсинга';

    /** @var string|null Множественное число. */
    protected static ?string $pluralModelLabel = 'Логи парсинга';

    /** @var int|null Порядок в группе навигации. */
    protected static ?int $navigationSort = 2;

    /**
     * Запретить создание записей через UI.
     *
     * @return bool
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Определить схему таблицы логов парсинга.
     *
     * @param Table $table Объект таблицы Filament.
     *
     * @return Table Настроенная таблица.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source.name')
                    ->label('Источник')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'success' => 'Успех',
                        'running' => 'Выполняется',
                        'failed' => 'Ошибка',
                        default => $state,
                    }),

                TextColumn::make('items_parsed')
                    ->label('Обработано')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('items_new')
                    ->label('Новых')
                    ->numeric()
                    ->color('success'),

                TextColumn::make('items_updated')
                    ->label('Обновлено')
                    ->numeric()
                    ->color('info'),

                TextColumn::make('items_skipped')
                    ->label('Пропущено')
                    ->numeric()
                    ->color('gray'),

                // Продолжительность: вычисляется из временны́х меток
                TextColumn::make('started_at')
                    ->label('Запущен')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable()
                    ->tooltip(fn (ParseLog $record): string => $record->started_at?->diffForHumans() ?? ''),

                TextColumn::make('finished_at')
                    ->label('Завершён')
                    ->dateTime('H:i:s')
                    ->placeholder('В процессе...')
                    ->tooltip(fn (ParseLog $record): string => $record->finished_at?->format('d.m.Y H:i:s') ?? ''),

                // Продолжительность в секундах
                TextColumn::make('duration')
                    ->label('Длит. (сек)')
                    ->state(fn (ParseLog $record): ?string => $record->durationSeconds() !== null
                        ? (string) $record->durationSeconds()
                        : null
                    )
                    ->placeholder('—'),

                TextColumn::make('error_message')
                    ->label('Ошибка')
                    ->limit(50)
                    ->tooltip(fn (ParseLog $record): ?string => $record->error_message)
                    ->placeholder('—')
                    ->color('danger'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'running' => 'Выполняется',
                        'success' => 'Успех',
                        'failed' => 'Ошибка',
                    ]),

                SelectFilter::make('source_id')
                    ->label('Источник')
                    ->relationship('source', 'name'),
            ])
            ->defaultSort('started_at', 'desc')
            ->poll('10s'); // Автообновление для отслеживания текущих запусков
    }

    /**
     * Получить массив страниц ресурса.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParseLogs::route('/'),
        ];
    }
}
