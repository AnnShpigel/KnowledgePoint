import { useEffect } from 'react';
import { authAPI } from '../services/api';

/**
 * Хук для автоматического обновления токена
 *
 * Проверяет токен каждые 50 минут и обновляет его,
 * чтобы пользователь не был разлогинен
 */
export const useTokenRefresh = () => {
  useEffect(() => {
    const token = localStorage.getItem('auth_token');

    // Если токена нет — ничего не делаем
    if (!token) return;

    // Обновляем токен каждые 50 минут (токен живёт 60 минут)
    const interval = setInterval(async () => {
      try {
        await authAPI.refresh();
        console.log('Токен успешно обновлён');
      } catch (error) {
        console.error('Ошибка обновления токена:', error);
        // Если обновление не удалось — разлогиниваем
        localStorage.removeItem('auth_token');
        localStorage.removeItem('user');
        window.location.href = '/';
      }
    }, 50 * 60 * 1000); // 50 минут

    // Очистка интервала при размонтировании
    return () => clearInterval(interval);
  }, []);
};
