import React from 'react';
import { Link } from 'react-router-dom';
import './Sidebar.css';

/**
 * Боковая панель навигации (выдвижная)
 *
 * Показывает меню пользователя: ссылки на личный кабинет (история поиска,
 * закладки, настройки профиля) и, для администраторов, раздел управления.
 * Закрывается кликом на оверлей или кнопку «×».
 *
 * @param {object}   props
 * @param {boolean}  props.isOpen  — отображать ли панель
 * @param {Function} props.onClose — callback закрытия
 * @returns {JSX.Element|null}
 */
const Sidebar = ({ isOpen, onClose }) => {
  if (!isOpen) return null;

  const user = JSON.parse(localStorage.getItem('user'));
  const isAdmin = user?.role === 'admin';
  const isLoggedIn = Boolean(localStorage.getItem('auth_token'));

  return (
    <div className="sidebar-overlay" onClick={onClose}>
      <aside className="sidebar" onClick={(e) => e.stopPropagation()}>
        <button className="sidebar-close" onClick={onClose}>×</button>

        <div className="sidebar-header">
          <div className="user-avatar">👤</div>
          <p className="user-email">{user?.email || 'Гость'}</p>
        </div>

        <nav>
          <ul>
            {isAdmin && (
              <>
                <li className="sidebar-section-title">Администрирование</li>
                <li><Link to="/admin/sources" onClick={onClose}>Управление источниками</Link></li>
                <li><Link to="/admin/parser" onClick={onClose}>Планирование парсинга</Link></li>
                <li><Link to="/admin/moderation" onClick={onClose}>Модерация контента</Link></li>
              </>
            )}

            {isLoggedIn && (
              <>
                <li className="sidebar-section-title">Личный кабинет</li>
                <li><Link to="/profile/bookmarks" onClick={onClose}>🔖 Мои закладки</Link></li>
                <li><Link to="/profile/history" onClick={onClose}>🔍 История поиска</Link></li>
              </>
            )}

            <li className="sidebar-section-title">Профиль</li>
            <li><Link to="/profile/settings" onClick={onClose}>Изменить email</Link></li>
            <li><Link to="/profile/password" onClick={onClose}>Изменить пароль</Link></li>
            {!isAdmin && (
              <li><Link to="/profile/delete" onClick={onClose}>Удалить профиль</Link></li>
            )}

            <li><Link to="/logout" className="logout-link" onClick={onClose}>Выйти</Link></li>
          </ul>
        </nav>
      </aside>
    </div>
  );
};

export default Sidebar;
