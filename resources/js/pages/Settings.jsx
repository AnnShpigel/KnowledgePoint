import React, { useState } from 'react';
import Navbar from '../components/layout/Navbar';
import Sidebar from '../components/layout/Sidebar';
import './Settings.css';

const Settings = () => {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [settings, setSettings] = useState({
    notifications: true,
    theme: 'light',
    language: 'ru'
  });

  const handleSave = (e) => {
    e.preventDefault();
    localStorage.setItem('user_settings', JSON.stringify(settings));
    alert('Настройки сохранены!');
    // В реальности: await fetch('/api/settings', { method: 'PUT', body: JSON.stringify(settings) })
  };

  return (
    <div className="settings-page">
      <Navbar onMenuClick={() => setIsSidebarOpen(!isSidebarOpen)} />
      <Sidebar isOpen={isSidebarOpen} onClose={() => setIsSidebarOpen(false)} />

      <div className="settings-content">
        <h1>Настройки</h1>
        <form onSubmit={handleSave} className="settings-form">
          <div className="setting-group">
            <label>
              <input
                type="checkbox"
                checked={settings.notifications}
                onChange={(e) => setSettings({ ...settings, notifications: e.target.checked })}
              />
              Уведомления о новых материалах
            </label>
          </div>

          <div className="setting-group">
            <label>Тема:</label>
            <select
              value={settings.theme}
              onChange={(e) => setSettings({ ...settings, theme: e.target.value })}
            >
              <option value="light">Светлая</option>
              <option value="dark">Тёмная</option>
            </select>
          </div>

          <div className="setting-group">
            <label>Язык:</label>
            <select
              value={settings.language}
              onChange={(e) => setSettings({ ...settings, language: e.target.value })}
            >
              <option value="ru">Русский</option>
              <option value="en">English</option>
            </select>
          </div>

          <button type="submit" className="save-button">Сохранить</button>
        </form>
      </div>
    </div>
  );
};

export default Settings;
