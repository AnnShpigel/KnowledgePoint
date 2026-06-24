<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ContentsResource\Pages;
use App\Models\Contents;
use App\Models\Source;
use App\Services\RdfService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Ресурс модерации контента (агрегированных материалов).
 *
 * Центральный инструмент администратора для управления потоком контента:
 *  - Просмотр списка материалов со статусом 'pending' (ожидают проверки)
 *  - Одиночное или массовое одобрение/отклонение
 *  - При одобрении — автоматическая синхронизация с Fuseki (RDF-граф)
 *  - При отклонении — удаление триплетов из Fuseki
 *  - Редактирование метаданных отдельных материалов
 *
 * Workflow модерации:
 *   pending → approved (+ Fuseki sync)
 *   pending → rejected (+ Fuseki remove)
 *   approved → rejected (+ Fuseki remove)
 *
 * @package App\Filament\Resources
 */
class ContentsResource extends Resource
{
    /** @var string|null Связанная модель Eloquent. */
    protected static ?string $model = Contents::class;

    /** @var string|null Иконка навигации. */
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    /** @var string|null Метка пункта навигации. */
    protected static ?string $navigationLabel = 'Модерация';

    /** @var string|null Группа навигации. */
    protected static ?string $navigationGroup = 'Контент';

    /** @var string|null Единственное число. */
    protected static ?string $modelLabel = 'Материал';

    /** @var string|null Множественное число. */
    protected static ?string $pluralModelLabel = 'Материалы';

    /** @var int|null Порядок в группе навигации. */
    protected static ?int $navigationSort = 1;

    /**
     * Счётчик ожидающих модерации в навигации.
     *
     * @return int|null
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Contents::where('status', 'pending')->count();
        return $count > 0 ? (string) $count : null;
    }

    /**
     * Цвет бейджа навигации.
     *
     * @return string|null
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Определить схему формы редактирования материала.
     *
     * @param Form $form Объект формы Filament.
     *
     * @return Form Настроенная форма.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Основное содержимое')
                ->schema([
                    TextInput::make('title')
                        ->label('Заголовок')
                        ->required()
                        ->maxLength(500)
                        ->columnSpanFull(),

                    Textarea::make('description')
                        ->label('Аннотация / описание')
                        ->rows(5)
                        ->maxLength(2000)
                        ->columnSpanFull(),

                    TextInput::make('author')
                        ->label('Автор(ы)')
                        ->maxLength(500),

                    TextInput::make('url')
                        ->label('URL первоисточника')
                        ->required()
                        ->url()
                        ->unique(ignoreRecord: true)
                        ->maxLength(500)
                        ->suffixAction(
                            \Filament\Forms\Components\Actions\Action::make('visit')
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->url(fn($record) => $record?->url)
                                ->openUrlInNewTab()
                        ),
                ])
                ->columns(2),

            Section::make('Классификация')
                ->schema([
                    Select::make('source_id')
                        ->label('Источник')
                        ->relationship('source', 'name')
                        ->required(),

                    Select::make('type')
                        ->label('Тип материала')
                        ->options([
                            'article' => 'Статья',
                            'book' => 'Книга',
                            'course' => 'Курс',
                            'video' => 'Видео',
                            'documentation' => 'Документация',
                        ])
                        ->required(),

                    Select::make('language')
                        ->label('Язык')
                        ->options([
                            'ru' => 'Русский',
                            'en' => 'English',
                        ])
                        ->required(),

                    DatePicker::make('published_at')
                        ->label('Дата публикации'),

                    Select::make('status')
                        ->label('Статус модерации')
                        ->options([
                            'pending' => 'Ожидает проверки',
                            'approved' => 'Одобрен',
                            'rejected' => 'Отклонён',
                        ])
                        ->required()
                        ->live(),

                    TextInput::make('external_id')
                        ->label('Внешний ID (DOI / slug)')
                        ->maxLength(255),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Определить схему таблицы материалов.
     *
     * @param Table $table Объект таблицы Filament.
     *
     * @return Table Настроенная таблица.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Заголовок')
                    ->limit(60)
                    ->searchable()
                    ->tooltip(fn(Contents $record): string => $record->title)
                    ->description(fn(Contents $record): string => $record->author ?? ''),

                TextColumn::make('source.name')
                    ->label('Источник')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'article' => 'primary',
                        'book' => 'success',
                        'course' => 'warning',
                        'video' => 'danger',
                        'documentation' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'article' => 'Статья',
                        'book' => 'Книга',
                        'course' => 'Курс',
                        'video' => 'Видео',
                        'documentation' => 'Документация',
                        default => $state,
                    }),

                TextColumn::make('language')
                    ->label('Язык')
                    ->badge()
                    ->color(fn(string $state): string => $state === 'ru' ? 'success' : 'info'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'approved' => 'Одобрен',
                        'pending' => 'На проверке',
                        'rejected' => 'Отклонён',
                        default => $state,
                    }),

                TextColumn::make('created_at')
                    ->label('Добавлен')
                    // Только одно форматирование — dateTime и since() вместе конфликтуют:
                    // since() перезаписывает dateTime format и показывает "X дней назад"
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->tooltip(fn(Contents $record): string => $record->created_at?->format('d.m.Y H:i') ?? ''),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // Фильтр status убран намеренно: вкладки (getTabs) в ListContents
                // уже фильтруют по статусу. Оставляем фильтрацию по источнику, типу и языку.
                SelectFilter::make('source_id')
                    ->label('Источник')
                    ->relationship('source', 'name'),

                SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        'article' => 'Статья',
                        'book' => 'Книга',
                        'course' => 'Курс',
                        'video' => 'Видео',
                        'documentation' => 'Документация',
                    ]),

                SelectFilter::make('language')
                    ->label('Язык')
                    ->options(['ru' => 'Русский', 'en' => 'English']),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Одобрить')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(Contents $record): bool => $record->status !== 'approved')
                    ->action(function (Contents $record): void {
                        static::syncStatus($record, 'approved');
                        Notification::make()
                            ->title('Материал одобрен и добавлен в RDF-граф')
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn(Contents $record): bool => $record->status !== 'rejected')
                    ->requiresConfirmation()
                    ->action(function (Contents $record): void {
                        static::syncStatus($record, 'rejected');
                        Notification::make()
                            ->title('Материал отклонён')
                            ->warning()
                            ->send();
                    }),

                ActionGroup::make([
                    Action::make('visit')
                        ->label('Открыть источник')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn(Contents $record): string => $record->url)
                        ->openUrlInNewTab(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')
                        ->label('Одобрить выбранные')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records): void {
                            $rdf = app(RdfService::class);
                            foreach ($records as $record) {
                                $record->update(['status' => 'approved']);
                                try {
                                    $rdf->syncContent($record->fresh());
                                } catch (\Throwable) {
                                    // Fuseki временно недоступен — не прерываем модерацию
                                }
                            }
                            Notification::make()
                                ->title("Одобрено материалов: {$records->count()}")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('reject_selected')
                        ->label('Отклонить выбранные')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records): void {
                            $rdf = app(RdfService::class);
                            foreach ($records as $record) {
                                $record->update(['status' => 'rejected']);
                                try {
                                    $rdf->removeContent($record);
                                } catch (\Throwable) {
                                    // Fuseki временно недоступен
                                }
                            }
                            Notification::make()
                                ->title("Отклонено материалов: {$records->count()}")
                                ->warning()
                                ->send();
                        }),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Получить массив страниц ресурса.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContents::route('/'),
            'edit' => Pages\EditContent::route('/{record}/edit'),
        ];
    }

    /**
     * Изменить статус материала и синхронизировать с Fuseki.
     *
     * При одобрении: upsert триплетов в RDF-граф.
     * При отклонении/возврате: удаление триплетов из RDF-графа.
     *
     * @param Contents $record    Запись контента.
     * @param string   $newStatus Новый статус: 'approved' | 'rejected' | 'pending'.
     *
     * @return void
     */
    private static function syncStatus(Contents $record, string $newStatus): void
    {
        $record->update(['status' => $newStatus]);
        $record->refresh();

        $rdf = app(RdfService::class);

        try {
            if ($newStatus === 'approved') {
                $rdf->syncContent($record);
            } else {
                $rdf->removeContent($record);
            }
        } catch (\Throwable) {
            // Fuseki временно недоступен — статус в MySQL уже обновлён,
            // синхронизацию можно выполнить позже командой php artisan rdf:sync
        }
    }
}
