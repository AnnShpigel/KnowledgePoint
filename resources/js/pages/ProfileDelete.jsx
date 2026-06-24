import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Navbar from '../components/layout/Navbar';
import './Profile.css';

const ProfileDelete = () => {
  const navigate = useNavigate();
  const [confirmation, setConfirmation] = useState('');

  const handleDelete = (e) => {
    e.preventDefault();
    if (confirmation !== 'УДАЛИТЬ') {
      alert('Введите "УДАЛИТЬ" для подтверждения');
      return;
    }

    // TODO: API запрос на удаление аккаунта
    localStorage.clear();
    alert('Профиль удалён');
    navigate('/');
    window.location.reload();
  };

  return (
    <div className="profile-page">
      <Navbar />
      <div className="profile-content">
        <h1 className="danger-title">Удалить профиль</h1>
        <div className="warning-box">
          <p>⚠️ Внимание! Это действие необратимо.</p>
          <p>Все ваши данные будут безвозвратно удалены.</p>
        </div>
        <form onSubmit={handleDelete} className="profile-form">
          <div className="form-group">
            <label>Введите "УДАЛИТЬ" для подтверждения:</label>
            <input
              type="text"
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              required
              placeholder="УДАЛИТЬ"
            />
          </div>
          <button type="submit" className="delete-button">Удалить профиль навсегда</button>
        </form>
      </div>
    </div>
  );
};

export default ProfileDelete;
