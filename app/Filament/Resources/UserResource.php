<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
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
use Illuminate\Database\Eloquent\Builder;

/**
 * Ресурс управления пользователями системы.
 *
 * Предоставляет CRUD-интерфейс для учётных записей.
 * Особенности:
 *  - Пароль обязателен при создании, опционален при редактировании
 *  - SoftDeletes — пользователи помечаются как удалённые, не стираются
 *  - Администратор не может удалить или заблокировать собственный аккаунт
 *    (кнопки скрыты для текущего пользователя)
 *
 * @package App\Filament\Resources
 */
class UserResource extends Resource
{
    /** @var string|null Связанная модель Eloquent. */
    protected static ?string $model = User::class;

    /** @var string|null Иконка Heroicons для навигации. */
    protected static ?string $navigationIcon = 'heroicon-o-users';

    /** @var string|null Метка пункта навигации. */
    protected static ?string $navigationLabel = 'Пользователи';

    /** @var string|null Группа навигации. */
    protected static ?string $navigationGroup = 'Система';

    /** @var string|null Единственное число для UI-сообщений. */
    protected static ?string $modelLabel = 'Пользователь';

    /** @var string|null Множественное число для UI-сообщений. */
    protected static ?string $pluralModelLabel = 'Пользователи';

    /** @var int|null Порядок в группе навигации. */
    protected static ?int $navigationSort = 1;

    /**
     * Определить схему формы создания/редактирования пользователя.
     *
     * @param Form $form Объект формы Filament.
     *
     * @return Form Настроенная форма.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255)
                ->columnSpanFull(),

            TextInput::make('password')
                ->label('Пароль')
                ->password()
                ->revealable()
                ->minLength(8)
                ->maxLength(255)
                ->dehydrateStateUsing(fn (string $state): string => bcrypt($state))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->helperText('При редактировании оставьте пустым, чтобы не менять пароль.'),

            Select::make('role')
                ->label('Роль')
                ->options([
                    'user' => 'Пользователь',
                    'admin' => 'Администратор',
                ])
                ->required()
                ->default('user'),

            Toggle::make('is_active')
                ->label('Активен')
                ->default(true)
                ->helperText('Неактивные пользователи не могут войти в систему.'),
        ]);
    }

    /**
     * Определить схему таблицы списка пользователей.
     *
     * @param Table $table Объект таблицы Filament.
     *
     * @return Table Настроенная таблица.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => 'Администратор',
                        'user' => 'Пользователь',
                        default => $state,
                    }),

                ToggleColumn::make('is_active')
                    ->label('Активен')
                    ->sortable()
                    // Запрещаем блокировать себя прямо из таблицы
                    ->disabled(fn (User $record): bool => $record->is(auth()->user())),

                TextColumn::make('created_at')
                    ->label('Зарегистрирован')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Роль')
                    ->options([
                        'user' => 'Пользователь',
                        'admin' => 'Администратор',
                    ]),

                SelectFilter::make('is_active')
                    ->label('Статус')
                    ->options([
                        '1' => 'Активен',
                        '0' => 'Заблокирован',
                    ]),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Редактировать'),

                    Action::make('toggle_active')
                        ->label(fn (User $record): string => $record->is_active ? 'Заблокировать' : 'Активировать')
                        ->icon(fn (User $record): string => $record->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                        ->color(fn (User $record): string => $record->is_active ? 'warning' : 'success')
                        ->requiresConfirmation()
                        // Администратор не может заблокировать сам себя
                        ->hidden(fn (User $record): bool => $record->is(auth()->user()))
                        ->action(function (User $record): void {
                            $record->update(['is_active' => !$record->is_active]);
                            Notification::make()
                                ->title($record->is_active ? 'Пользователь активирован' : 'Пользователь заблокирован')
                                ->success()
                                ->send();
                        }),

                    DeleteAction::make()
                        ->label('Удалить')
                        // Администратор не может удалить сам себя
                        ->hidden(fn (User $record): bool => $record->is(auth()->user()))
                        ->successNotificationTitle('Пользователь удалён'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Получить массив страниц ресурса.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Базовый запрос: только не удалённые пользователи.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutTrashed();
    }
}
