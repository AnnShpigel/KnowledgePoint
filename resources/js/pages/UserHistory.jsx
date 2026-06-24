import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Search, X } from 'lucide-react';
import Navbar from '../components/layout/Navbar';
import AuthModal from '../components/AuthModal';
import { userAPI } from '../services/api';
import './ProfilePage.css';

const formatDate = (iso) => {
  try {
    return new Date(iso).toLocaleString('ru-RU', {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    });
  } catch { return iso; }
};

const UserHistory = () => {
  const navigate = useNavigate();
  const [showAuthModal, setShowAuthModal] = useState(false);

  const [entries, setEntries] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [deletingIds, setDeletingIds] = useState(new Set());
  const [clearing, setClearing] = useState(false);
  const [pagination, setPagination] = useState(null);
  const [page, setPage] = useState(1);

  useEffect(() => {
    if (!localStorage.getItem('auth_token')) navigate('/');
  }, [navigate]);

  const fetchHistory = useCallback(async (pageNum = 1) => {
    setLoading(true);
    setError(null);
    try {
      const response = await userAPI.getHistory(20);
      const data = response.data;
      setEntries(data.data ?? []);
      setPagination({
        currentPage: data.current_page,
        lastPage: data.last_page,
        total: data.total,
      });
    } catch (err) {
      setError(err.message || 'Не удалось загрузить историю поиска');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchHistory(page); }, [fetchHistory, page]);

  const handleDelete = async (id) => {
    setDeletingIds((prev) => new Set(prev).add(id));
    try {
      await userAPI.deleteHistoryEntry(id);
      setEntries((prev) => prev.filter((e) => e.id !== id));
    } catch (err) {
      setError(err.message || 'Не удалось удалить запись');
    } finally {
      setDeletingIds((prev) => { const s = new Set(prev); s.delete(id); return s; });
    }
  };

  const handleClearAll = async () => {
    if (!window.confirm('Удалить всю историю поиска? Это действие необратимо.')) return;
    setClearing(true);
    try {
      await userAPI.clearHistory();
      setEntries([]);
      setPagination(null);
    } catch (err) {
      setError(err.message || 'Не удалось очистить историю');
    } finally {
      setClearing(false);
    }
  };

  const handleRepeatSearch = (query) => {
    navigate(`/?q=${encodeURIComponent(query)}`);
  };

  return (
    <div className="profile-page">
      <Navbar onAuthClick={() => setShowAuthModal(true)} />

      <main className="profile-main">
        <div className="profile-container">
          <div className="history-page-header">
            <h1 className="profile-page-title" style={{ margin: 0 }}>История поиска</h1>
            {entries.length > 0 && (
              <button className="history-clear-btn" onClick={handleClearAll} disabled={clearing}>
                {clearing ? 'Очистка…' : 'Очистить всё'}
              </button>
            )}
          </div>

          {error && <div className="history-error-box">⚠️ {error}</div>}

          {loading ? (
            <div className="page-loading">
              <div className="spinner" />
              <p>Загрузка…</p>
            </div>
          ) : entries.length === 0 && !error ? (
            <div className="history-empty">
              <p className="history-empty-icon">🔍</p>
              <p className="history-empty-title">История поиска пуста</p>
              <p className="history-empty-hint">Ваши поисковые запросы будут сохранены здесь</p>
            </div>
          ) : (
            <ul className="history-list">
              {entries.map((entry) => (
                <li key={entry.id} className="history-item">
                  <div className="history-item-body">
                    <span className="history-query">{entry.query}</span>
                    <span className="history-date">{formatDate(entry.created_at)}</span>
                  </div>
                  <div className="history-item-actions">
                    <button
                      className="history-repeat-btn"
                      onClick={() => handleRepeatSearch(entry.query)}
                    >
                      <Search size={13} style={{ display: 'inline', marginRight: 4 }} />
                      Повторить
                    </button>
                    <button
                      className="history-delete-btn"
                      onClick={() => handleDelete(entry.id)}
                      disabled={deletingIds.has(entry.id)}
                      aria-label="Удалить"
                    >
                      <X size={15} />
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}

          {pagination && pagination.lastPage > 1 && (
            <div className="profile-pagination">
              <button
                className="profile-pagination-btn"
                onClick={() => setPage((p) => Math.max(p - 1, 1))}
                disabled={pagination.currentPage === 1}
              >
                ← Назад
              </button>
              <span className="profile-pagination-info">
                Страница <strong>{pagination.currentPage}</strong> из <strong>{pagination.lastPage}</strong>
              </span>
              <button
                className="profile-pagination-btn"
                onClick={() => setPage((p) => Math.min(p + 1, pagination.lastPage))}
                disabled={pagination.currentPage === pagination.lastPage}
              >
                Вперёд →
              </button>
            </div>
          )}
        </div>
      </main>

      {showAuthModal && <AuthModal onClose={() => setShowAuthModal(false)} />}
    </div>
  );
};

export default UserHistory;
