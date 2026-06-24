import React, { useState, useEffect } from 'react';
import Navbar from '../components/layout/Navbar';
import './Profile.css';
import { useNavigate } from 'react-router-dom';
import Loader from '../components/Loader';
import { profileAPI } from '../services/api';

const ProfileSettings = () => {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const navigate = useNavigate();

  useEffect(() => {
    const user = JSON.parse(localStorage.getItem('user'));
    if (user) setEmail(user.email);
  }, []);

  const handleSave = (e) => {
    e.preventDefault();
    // TODO: API запрос на изменение данных
    alert('Функция в разработке');
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!email || !email.includes('@')) {
      setError('Пожалуйста, введите корректный email');
      return;
    }
    setLoading(true);
    setMessage('');
    setError('');

    try {
      await profileAPI.updateEmail(email);
      setMessage('Email успешно обновлён!');
      // Обновляем данные в localStorage
      const user = JSON.parse(localStorage.getItem('user'));
      user.email = email;
      localStorage.setItem('user', JSON.stringify(user));
      setTimeout(() => navigate('/'), 2000);
    } catch (err) {
      setError(err.message || 'Не удалось обновить email');
    } finally {
      setLoading(false);
    }
  };

  if (loading) return <Loader />;

  return (
    <div className="profile-page">
      <Navbar />
      <div className="profile-content">
        {/* <h1>Изменить данные профиля</h1>
        <form onSubmit={handleSave} className="profile-form">
          <div className="form-group">
            <label>Email:</label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </div>
          <button type="submit" className="save-button">Сохранить</button>
        </form> */}

        <h2 className="page-title" style={{ fontFamily: 'var(--font-serif)', color: 'var(--color-dark)' }}>
          Изменить email
        </h2>
        {message && <p style={{ color: 'green', marginBottom: '1rem' }}>{message}</p>}
        {error && <p style={{ color: 'red', marginBottom: '1rem' }}>{error}</p>}
        <form onSubmit={handleSubmit} className="profile-form">
          <div style={{ marginBottom: '1rem' }}>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="Новый email"
              style={{
                width: '100%',
                padding: '0.75rem',
                border: '1px solid #cbd5e0',
                borderRadius: '0.375rem',
                fontSize: '1rem',
              }}
            />
          </div>
          <button
            type="submit"
            style={{
              background: 'var(--color-accent)',
              color: 'white',
              border: 'none',
              padding: '0.75rem 1.5rem',
              borderRadius: '0.375rem',
              cursor: 'pointer',
              fontSize: '1rem',
            }}
          >
            Сохранить
          </button>
        </form>
      </div>
    </div>
  );
};

export default ProfileSettings;
