<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Модель пользователя системы агрегации знаний.
 *
 * Реализует два контракта Filament:
 *  - FilamentUser — определяет право доступа к административной панели
 *  - HasName — предоставляет имя для отображения в интерфейсе панели
 *
 * Без HasName Filament пытается получить $user->name (null для нашей модели),
 * что вызывает TypeError в FilamentManager::getUserName().
 *
 * Поддерживает два механизма аутентификации:
 *  - Laravel Sanctum (API-токены) — для REST API
 *  - Session auth (Filament) — для административной панели /admin
 *
 * Роли:
 *  - 'user'  — обычный пользователь, доступ только к публичному API
 *  - 'admin' — администратор, полный доступ к панели управления
 *
 * @property int              $id
 * @property string           $email
 * @property string           $password
 * @property string           $role              user | admin
 * @property bool             $is_active
 * @property \Carbon\Carbon|null $email_verified_at
 * @property \Carbon\Carbon   $created_at
 * @property \Carbon\Carbon   $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @package App\Models
 */
class User extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Поля, разрешённые для массового заполнения.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'role',
        'is_active',
        'email_verified_at',
    ];

    /**
     * Поля, скрытые при сериализации модели в JSON.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Приведение типов атрибутов.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    /**
     * Определить право доступа к Filament-панели.
     *
     * Доступ разрешён только активным администраторам.
     * Мягко удалённые пользователи автоматически исключаются (SoftDeletes).
     *
     * @param Panel $panel Панель, к которой запрашивается доступ.
     *
     * @return bool true — если роль 'admin' и аккаунт активен.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'admin' && $this->is_active;
    }

    /**
     * Получить имя пользователя для отображения в интерфейсе Filament.
     *
     * Реализует контракт HasName. Filament отображает это имя
     * в шапке панели, аватаре и всплывающих уведомлениях.
     * Поскольку модель не имеет поля name, возвращаем email.
     *
     * @return string Email пользователя как отображаемое имя.
     */
    public function getFilamentName(): string
    {
        return $this->email;
    }

    /**
     * Проверить, является ли пользователь администратором.
     *
     * @return bool true, если роль пользователя — 'admin'.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
