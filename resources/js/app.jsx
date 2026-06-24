import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import Home from './pages/Home';
import ArticleDetail from './pages/ArticleDetail';
import UserHistory from './pages/UserHistory';
import UserBookmarks from './pages/UserBookmarks';
import ProfilePassword from './pages/ProfilePassword';
import Logout from './pages/Logout';
import { useTokenRefresh } from './hooks/useTokenRefresh';
import { authAPI } from './services/api';


const LoadingScreen = () => (
  <div style={{
    minHeight: '100vh', display: 'flex', flexDirection: 'column',
    alignItems: 'center', justifyContent: 'center', gap: '1rem',
    background: '#F8FAFC', fontFamily: "'Manrope', sans-serif",
  }}>
    <div style={{
      width: 40, height: 40,
      border: '3px solid #E2E8F0', borderTopColor: '#4F46E5',
      borderRadius: '50%', animation: 'appSpin .8s linear infinite',
    }} />
    <p style={{ color: '#64748B', fontSize: '.9375rem', margin: 0 }}>Загрузка…</p>
    <style>{`@keyframes appSpin{to{transform:rotate(360deg)}}`}</style>
  </div>
);

const App = () => {
  const [isLoading, setIsLoading] = useState(true);
  useTokenRefresh();

  useEffect(() => {
    const checkAuth = async () => {
      const token = localStorage.getItem('auth_token');
      if (token) {
        try { await authAPI.me(); }
        catch {
          localStorage.removeItem('auth_token');
          localStorage.removeItem('user');
        }
      }
      setIsLoading(false);
    };
    checkAuth();
  }, []);

  if (isLoading) return <LoadingScreen />;

  return (
    <Router>
      <Routes>
        {/* Главная */}
        <Route path="/" element={<Home />} />
        <Route path="/article/:id" element={<ArticleDetail />} />

        {/* Личный кабинет */}
        <Route path="/profile/history" element={<UserHistory />} />
        <Route path="/profile/bookmarks" element={<UserBookmarks />} />
        {/* /profile/settings → только смена пароля */}
        <Route path="/profile/settings" element={<ProfilePassword />} />
        <Route path="/profile/password" element={<ProfilePassword />} />

        {/* Служебные */}
        <Route path="/logout" element={<Logout />} />

        {/* Админ — используется Filament (/admin) */}

        {/* Все остальные → главная */}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Router>
  );
};

const container = document.getElementById('app');
if (container) {
  const root = createRoot(container);
  root.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}
