<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Обновление email пользователя.
     */
    public function updateEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email,' . auth()->id(),
        ]);

        /**
         * @var User $user
         */
        $user = auth()->user();
        $user->email = $request->email;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Email успешно обновлён.',
            'user' => $user,
        ]);
    }

    /**
     * Обновление пароля пользователя.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:6|confirmed',
        ]);

        /**
         * @var User $user
         */
        $user = auth()->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Текущий пароль неверен.'],
            ]);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Пароль успешно изменён.',
        ]);
    }

    /**
     * Удаление профиля пользователя.
     */
    public function deleteProfile(Request $request)
    {
        $request->validate([
            'password' => 'required',
        ]);

        /**
         * @var User $user
         */
        $user = auth()->user();

        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Пароль неверен.'],
            ]);
        }

        $user->delete();

        // Удаляем токен
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Профиль успешно удалён.',
        ]);
    }
}
