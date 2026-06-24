import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Lock, CheckCircle, AlertCircle } from 'lucide-react';
import Navbar from '../components/layout/Navbar';
import AuthModal from '../components/AuthModal';
import { profileAPI } from '../services/api';
import './ProfilePage.css';

const ProfilePassword = () => {
  const navigate = useNavigate();
  const [showAuthModal, setShowAuthModal] = useState(false);

  const [form, setForm] = useState({ current: '', next: '', confirm: '' });
  const [status, setStatus] = useState(null); // null | 'loading' | 'success' | 'error'
  const [message, setMessage] = useState('');

  useEffect(() => {
    if (!localStorage.getItem('auth_token')) navigate('/');
  }, [navigate]);

  const handleChange = (field) => (e) =>
    setForm((prev) => ({ ...prev, [field]: e.target.value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setMessage('');

    if (form.next.length < 5) {
      setStatus('error');
      setMessage('Новый пароль должен содержать не менее 5 символов.');
      return;
    }
    if (form.next !== form.confirm) {
      setStatus('error');
      setMessage('Пароли не совпадают.');
      return;
    }

    setStatus('loading');
    try {
      await profileAPI.updatePassword(form.current, form.next);
      setStatus('success');
      setMessage('Пароль успешно изменён.');
      setForm({ current: '', next: '', confirm: '' });
    } catch (err) {
      setStatus('error');
      setMessage(err.message || 'Не удалось изменить пароль. Проверьте текущий пароль.');
    }
  };

  return (
    <div className="profile-page">
      <Navbar onAuthClick={() => setShowAuthModal(true)} />

      <main className="profile-main">
        <div className="profile-container">
          <h1 className="profile-page-title">Настройки</h1>

          <div className="profile-card">
            <div className="profile-card-header">
              <div className="profile-card-icon">
                <Lock size={20} color="#4F46E5" />
              </div>
              <div>
                <h2 className="profile-card-title">Изменить пароль</h2>
                <p className="profile-card-desc">Для безопасности используйте надёжный пароль</p>
              </div>
            </div>

            <form onSubmit={handleSubmit} className="profile-form">
              <div className="form-field">
                <label className="form-label">Текущий пароль</label>
                <input
                  type="password"
                  className="form-input"
                  value={form.current}
                  onChange={handleChange('current')}
                  placeholder="Введите текущий пароль"
                  required
                  autoComplete="current-password"
                />
              </div>

              <div className="form-field">
                <label className="form-label">Новый пароль</label>
                <input
                  type="password"
                  className="form-input"
                  value={form.next}
                  onChange={handleChange('next')}
                  placeholder="Не менее 5 символов"
                  required
                  minLength={5}
                  autoComplete="new-password"
                />
              </div>

              <div className="form-field">
                <label className="form-label">Повторите новый пароль</label>
                <input
                  type="password"
                  className="form-input"
                  value={form.confirm}
                  onChange={handleChange('confirm')}
                  placeholder="Повторите новый пароль"
                  required
                  autoComplete="new-password"
                />
              </div>

              {message && (
                <div className={`form-alert ${status === 'success' ? 'form-alert--success' : 'form-alert--error'}`}>
                  {status === 'success'
                    ? <CheckCircle size={16} />
                    : <AlertCircle size={16} />}
                  {message}
                </div>
              )}

              <button
                type="submit"
                className="form-submit"
                disabled={status === 'loading'}
              >
                {status === 'loading' ? 'Сохранение…' : 'Сменить пароль'}
              </button>
            </form>
          </div>
        </div>
      </main>

      {showAuthModal && <AuthModal onClose={() => setShowAuthModal(false)} />}
    </div>
  );
};

export default ProfilePassword;
