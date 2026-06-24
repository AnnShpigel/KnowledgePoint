import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { authAPI } from '../services/api';

const Logout = () => {
  const navigate = useNavigate();

  useEffect(() => {
    const performLogout = async () => {
      try {
        // Вызываем API для удаления токена на сервере
        await authAPI.logout();
      } catch (error) {
        console.error('Ошибка при выходе:', error);
        // Даже если запрос упал — всё равно очищаем локальные данные
      } finally {
        // Редирект на главную
        setTimeout(() => {
          navigate('/');
          window.location.reload(); // Перезагружаем для обновления состояния
        }, 500);
      }
    };

    performLogout();
  }, [navigate]);

  return (
    <div style={{
      minHeight: '100vh',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      background: 'var(--color-light)'
    }}>
      <p style={{ fontSize: '1.2rem', color: 'var(--color-dark)' }}>
        Выход из системы...
      </p>
    </div>
  );
};

export default Logout;
