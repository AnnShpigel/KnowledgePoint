import { useState, useEffect, useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Network, User, LogOut, Settings, Bookmark, Clock } from 'lucide-react';
import { authAPI } from '../../services/api';
import './Navbar.css';

const Navbar = ({ onAuthClick }) => {
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [user, setUser] = useState(null);
  const [showDropdown, setShowDropdown] = useState(false);
  const dropdownRef = useRef(null);
  const navigate = useNavigate();

  useEffect(() => {
    const sync = () => {
      const token = localStorage.getItem('auth_token');
      const raw = localStorage.getItem('user');
      setIsAuthenticated(Boolean(token));
      if (raw) { try { setUser(JSON.parse(raw)); } catch {} } else setUser(null);
    };
    sync();
    window.addEventListener('authChange', sync);
    return () => window.removeEventListener('authChange', sync);
  }, []);

  useEffect(() => {
    const close = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setShowDropdown(false);
      }
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  const handleLogout = async () => {
    try { await authAPI.logout(); } catch {}
    setIsAuthenticated(false);
    setUser(null);
    setShowDropdown(false);
    window.dispatchEvent(new Event('authChange'));
    navigate('/');
  };

  return (
    <header className="app-header">
      <div className="header-inner">
        <Link to="/" className="header-logo">
          <div className="header-logo-icon">
            <Network size={24} color="white" />
          </div>
          <span className="header-logo-text">Агрегатор знаний</span>
        </Link>

        <div ref={dropdownRef} className="header-dropdown">
          {!isAuthenticated ? (
            <button className="header-auth-btn" onClick={onAuthClick}>
              Войти
            </button>
          ) : (
            <>
              <button
                className="header-avatar-btn"
                onClick={() => setShowDropdown(!showDropdown)}
                aria-label="Меню профиля"
              >
                <User size={20} color="#4F46E5" />
              </button>

              {showDropdown && (
                <div className="header-dropdown-menu">
                  {user?.email && (
                    <div className="dropdown-email-row">{user.email}</div>
                  )}
                  <Link
                    to="/profile/bookmarks"
                    className="dropdown-item"
                    onClick={() => setShowDropdown(false)}
                  >
                    <Bookmark size={16} color="#6B7280" />
                    Сохранённые
                  </Link>
                  <Link
                    to="/profile/history"
                    className="dropdown-item"
                    onClick={() => setShowDropdown(false)}
                  >
                    <Clock size={16} color="#6B7280" />
                    История поиска
                  </Link>
                  <Link
                    to="/profile/settings"
                    className="dropdown-item"
                    onClick={() => setShowDropdown(false)}
                  >
                    <Settings size={16} color="#6B7280" />
                    Настройки
                  </Link>
                  <button className="dropdown-item danger" onClick={handleLogout}>
                    <LogOut size={16} />
                    Выйти
                  </button>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </header>
  );
};

export default Navbar;
