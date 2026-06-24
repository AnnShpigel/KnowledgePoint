import { useState } from 'react';
import { X } from 'lucide-react';
import { authAPI } from '../services/api';

const S = {
  overlay: {
    position: 'fixed', inset: 0, zIndex: 100,
    display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '1rem',
  },
  backdrop: {
    position: 'absolute', inset: 0,
    background: 'rgba(15, 23, 42, 0.45)',
    backdropFilter: 'blur(4px)',
    WebkitBackdropFilter: 'blur(4px)',
  },
  modal: {
    position: 'relative', width: '100%', maxWidth: '28rem',
    borderRadius: '1rem', padding: '2rem',
    background: 'rgba(255, 255, 255, 0.88)',
    backdropFilter: 'blur(32px)', WebkitBackdropFilter: 'blur(32px)',
    border: '1px solid rgba(255,255,255,0.35)',
    boxShadow: '0 24px 64px rgba(0,0,0,0.15)',
    fontFamily: "'Manrope', sans-serif",
  },
  closeBtn: {
    position: 'absolute', top: '1rem', right: '1rem',
    width: '2rem', height: '2rem',
    display: 'flex', alignItems: 'center', justifyContent: 'center',
    background: 'none', border: 'none', cursor: 'pointer',
    borderRadius: '0.5rem', color: '#6B7280', transition: 'background 0.15s',
  },
  tabRow: { display: 'flex', gap: '1.5rem', marginBottom: '1.5rem', borderBottom: '1px solid #E5E7EB' },
  tab: (active) => ({
    paddingBottom: '0.75rem',
    border: 'none', background: 'none', cursor: 'pointer',
    fontFamily: "'Manrope', sans-serif", fontWeight: 700, fontSize: '1.125rem',
    borderBottom: `2px solid ${active ? '#4F46E5' : 'transparent'}`,
    color: active ? '#4F46E5' : '#64748B', transition: 'all 0.15s',
  }),
  label: { display: 'block', marginBottom: '0.5rem', fontWeight: 500, fontSize: '0.875rem', color: '#374151' },
  input: {
    width: '100%', padding: '0.75rem 1rem',
    border: '1px solid #E5E7EB', borderRadius: '0.75rem',
    fontFamily: "'Manrope', sans-serif", fontSize: '0.9375rem',
    background: 'rgba(255,255,255,0.6)', outline: 'none',
    transition: 'border-color 0.15s', boxSizing: 'border-box',
  },
  submitBtn: {
    width: '100%', padding: '0.75rem 1.5rem', marginTop: '0.5rem',
    background: '#4F46E5', color: 'white', border: 'none',
    borderRadius: '0.75rem', fontFamily: "'Manrope', sans-serif",
    fontWeight: 600, fontSize: '0.9375rem', cursor: 'pointer',
    transition: 'background 0.2s',
  },
};

const AuthModal = ({ onClose }) => {
  const [tab, setTab] = useState('login');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError(''); setSuccess(''); setLoading(true);
    const email = e.target.email.value;
    const password = e.target.password.value;
    try {
      if (tab === 'login') {
        await authAPI.login(email, password);
        setSuccess('Вы успешно вошли в систему!');
      } else {
        const confirm = e.target.confirmPassword?.value;
        if (confirm && confirm !== password) { setError('Пароли не совпадают'); setLoading(false); return; }
        await authAPI.register(email, password);
        setSuccess('Регистрация прошла успешно!');
      }
      window.dispatchEvent(new Event('authChange'));
      setTimeout(() => onClose(), 1500);
    } catch (err) {
      setError(err.message || 'Произошла ошибка. Попробуйте ещё раз.');
    } finally {
      setLoading(false);
    }
  };

  const switchTab = (t) => { setTab(t); setError(''); setSuccess(''); };

  return (
    <div style={S.overlay}>
      <div style={S.backdrop} onClick={onClose} />
      <div style={S.modal}>
        <button style={S.closeBtn} onClick={onClose} aria-label="Закрыть">
          <X size={18} />
        </button>

        <div style={S.tabRow}>
          <button style={S.tab(tab === 'login')} onClick={() => switchTab('login')}>Вход</button>
          <button style={S.tab(tab === 'register')} onClick={() => switchTab('register')}>Регистрация</button>
        </div>

        {success ? (
          <div style={{ padding: '2rem 0', textAlign: 'center', color: '#059669', fontWeight: 600 }}>
            ✓ {success}
          </div>
        ) : (
          <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
            <div>
              <label style={S.label}>Email</label>
              <input name="email" type="email" required placeholder="example@mail.ru" style={S.input} />
            </div>
            <div>
              <label style={S.label}>Пароль</label>
              <input name="password" type="password" required minLength={5} placeholder="••••••••" style={S.input} />
            </div>
            {tab === 'register' && (
              <div>
                <label style={S.label}>Повторите пароль</label>
                <input name="confirmPassword" type="password" required placeholder="••••••••" style={S.input} />
              </div>
            )}
            {error && <p style={{ color: '#DC2626', fontSize: '0.875rem', margin: 0 }}>{error}</p>}
            <button type="submit" disabled={loading} style={{ ...S.submitBtn, opacity: loading ? 0.7 : 1 }}>
              {loading ? '…' : tab === 'login' ? 'Войти' : 'Зарегистрироваться'}
            </button>
          </form>
        )}
      </div>
    </div>
  );
};

export default AuthModal;
