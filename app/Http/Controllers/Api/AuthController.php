<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Контроллер для управления аутентификацией пользователей
 *
 * Обрабатывает регистрацию, вход и выход из системы
 * Использует Laravel Sanctum для управления токенами (JWT-подобная система)
 */
class AuthController extends Controller
{
    /**
     * Регистрация нового пользователя
     *
     * Принимает email и password, создаёт нового пользователя в БД,
     * присваивает роль 'user' по умолчанию и возвращает токен доступа
     *
     * @param Request $request - объект HTTP-запроса с данными формы
     * @return \Illuminate\Http\JsonResponse - JSON-ответ с токеном и данными пользователя
     */
    public function register(Request $request)
    {
        // Валидация входящих данных
        // email должен быть уникальным в таблице users
        // password минимум 5 символов (можно увеличить до 8 в продакшене)
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:5',
        ], [
            // Кастомные сообщения об ошибках на русском языке
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Некорректный формат email',
            'email.unique' => 'Пользователь с таким email уже существует',
            'password.required' => 'Пароль обязателен для заполнения',
            'password.min' => 'Пароль должен содержать минимум :min символов',
        ]);

        // Если валидация не прошла — возвращаем ошибки с кодом 422 (Unprocessable Entity)
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Создание нового пользователя в БД
            // Hash::make() — безопасное хеширование пароля (bcrypt по умолчанию)
            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'user', // По умолчанию все новые пользователи имеют роль 'user'
                'is_active' => true,
                'email_verified_at' => now(), // В реальности здесь должна быть верификация через email
            ]);

            // Создание токена доступа для пользователя
            // Токен будет действителен 60 минут (настроено в config/sanctum.php)
            // Токен привязан к конкретному пользователю и устройству
            $token = $user->createToken('auth_token', ['*'], now()->addMinutes(60))->plainTextToken;

            // Возвращаем успешный ответ с токеном и данными пользователя
            return response()->json([
                'success' => true,
                'message' => 'Пользователь успешно зарегистрирован',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                        'role' => $user->role,
                    ],
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => 3600, // Время жизни токена в секундах (60 минут)
                ]
            ], 201); // HTTP 201 Created

        } catch (\Exception $e) {
            // Обработка непредвиденных ошибок (проблемы с БД, и т.д.)
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при регистрации пользователя',
                'error' => $e->getMessage()
            ], 500); // HTTP 500 Internal Server Error
        }
    }

    /**
     * Вход пользователя в систему (авторизация)
     *
     * Проверяет учётные данные (email и password),
     * при успехе создаёт новый токен доступа
     *
     * @param Request $request - объект HTTP-запроса с email и password
     * @return \Illuminate\Http\JsonResponse - JSON-ответ с токеном или ошибкой
     */
    public function login(Request $request)
    {
        // Валидация входящих данных
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ], [
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Некорректный формат email',
            'password.required' => 'Пароль обязателен для заполнения',
        ]);

        // Если валидация не прошла — возвращаем ошибки
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Поиск пользователя по email в базе данных
        $user = User::where('email', $request->email)->first();

        // Проверка существования пользователя и корректности пароля
        // Hash::check() сравнивает введённый пароль с хешем из БД
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный email или пароль'
            ], 401); // HTTP 401 Unauthorized
        }

        // Проверка активности аккаунта
        // Если аккаунт заблокирован администратором — запрещаем вход
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Ваш аккаунт заблокирован. Обратитесь к администратору.'
            ], 403); // HTTP 403 Forbidden
        }

        // Удаление всех старых токенов пользователя перед созданием нового
        // Это гарантирует, что в системе всегда только один активный токен на устройство
        // Можно закомментировать, если нужна поддержка нескольких устройств одновременно
        $user->tokens()->delete();

        // Создание нового токена доступа
        // Токен действителен 60 минут, после чего потребуется повторный вход
        $token = $user->createToken('auth_token', ['*'], now()->addMinutes(60))->plainTextToken;

        // Возвращаем успешный ответ с токеном и информацией о пользователе
        return response()->json([
            'success' => true,
            'message' => 'Вход выполнен успешно',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => 3600, // 60 минут в секундах
            ]
        ], 200); // HTTP 200 OK
    }

    /**
     * Выход пользователя из системы (logout)
     *
     * Удаляет текущий токен доступа, что делает его недействительным
     * Требует аутентификации через middleware 'auth:sanctum'
     *
     * @param Request $request - объект HTTP-запроса (содержит токен в заголовке Authorization)
     * @return \Illuminate\Http\JsonResponse - JSON-ответ с подтверждением выхода
     */
    public function logout(Request $request)
    {
        try {
            // Удаление текущего токена из БД
            // $request->user() — возвращает текущего аутентифицированного пользователя
            // currentAccessToken() — получает токен, с которым пришёл запрос
            // delete() — удаляет токен из таблицы personal_access_tokens
            $request->user()->currentAccessToken()->delete();

            // Возвращаем успешный ответ
            return response()->json([
                'success' => true,
                'message' => 'Выход выполнен успешно'
            ], 200);

        } catch (\Exception $e) {
            // Обработка ошибок (на случай проблем с БД)
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при выходе из системы',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получение информации о текущем пользователе
     *
     * Возвращает данные авторизованного пользователя по токену
     * Полезно для проверки валидности сессии на фронтенде
     *
     * @param Request $request - объект HTTP-запроса с токеном
     * @return \Illuminate\Http\JsonResponse - JSON с данными пользователя
     */
    public function me(Request $request)
    {
        // $request->user() автоматически извлекает пользователя по токену
        // благодаря middleware 'auth:sanctum'
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
            ]
        ], 200);
    }

    /**
     * Обновление (продление) токена доступа
     *
     * Если токен близок к истечению, пользователь может обновить его
     * без повторного ввода пароля
     *
     * @param Request $request - объект HTTP-запроса с текущим токеном
     * @return \Illuminate\Http\JsonResponse - JSON с новым токеном
     */
    public function refresh(Request $request)
    {
        try {
            $user = $request->user();

            // Удаляем текущий токен
            $request->user()->currentAccessToken()->delete();

            // Создаём новый токен с продлённым сроком действия
            $token = $user->createToken('auth_token', ['*'], now()->addMinutes(60))->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Токен успешно обновлён',
                'data' => [
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении токена',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
