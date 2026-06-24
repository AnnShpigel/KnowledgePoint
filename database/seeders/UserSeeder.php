<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Сидер пользователей системы.
 *
 * Создаёт демонстрационные учётные записи для разработки и презентации.
 * Использует updateOrCreate для идемпотентности — повторный запуск
 * не создаёт дубли.
 *
 * Учётные записи:
 *  - admin@library.loc / password123 — администратор, доступ к /admin
 *  - user@library.loc  / password123 — обычный пользователь, только API
 *
 * ВАЖНО: В production необходимо сменить пароли и удалить тестового пользователя.
 */
class UserSeeder extends Seeder
{
    /**
     * Наполнить таблицу users тестовыми учётными записями.
     *
     * @return void
     */
    public function run(): void
    {
        // Администратор — полный доступ к панели управления
        User::updateOrCreate(
            ['email' => 'admin@library.loc'],
            [
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // Обычный пользователь — доступ только к API и публичному поиску
        User::updateOrCreate(
            ['email' => 'user@library.loc'],
            [
                'password' => Hash::make('password123'),
                'role' => 'user',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // Дополнительный заблокированный пользователь для демонстрации блокировки
        User::updateOrCreate(
            ['email' => 'blocked@library.loc'],
            [
                'password' => Hash::make('password123'),
                'role' => 'user',
                'is_active' => false,
                'email_verified_at' => now(),
            ],
        );
    }
}
