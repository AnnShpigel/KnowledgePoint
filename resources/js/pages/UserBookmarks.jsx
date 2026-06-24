import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Shield, ExternalLink, Bookmark, X } from 'lucide-react';
import Navbar from '../components/layout/Navbar';
import AuthModal from '../components/AuthModal';
import { userAPI } from '../services/api';
import './ProfilePage.css';

const TYPE_LABELS = {
  article: 'Статья', video: 'Видео', course: 'Курс',
  documentation: 'Документация', book: 'Книга',
};

const formatDate = (iso) => {
  if (!iso) return '';
  try { return new Date(iso).toLocaleDateString('ru-RU'); }
  catch { return iso; }
};

const BookmarkCard = ({ bookmark, isRemoving, onRemove, onOpen }) => {
  const c = bookmark.content ?? {};
  const typeLabel = TYPE_LABELS[c.type] ?? c.type ?? 'Материал';

  return (
    <div
      className="history-item"
      style={{ flexDirection: 'column', alignItems: 'stretch', gap: 0, opacity: isRemoving ? 0.5 : 1, cursor: 'pointer' }}
      onClick={() => onOpen(c)}
    >
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '0.75rem' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.5rem', flexWrap: 'wrap' }}>
            <span style={{
              padding: '0.2rem 0.625rem', background: '#EEF2FF', color: '#4F46E5',
              borderRadius: '9999px', fontSize: '0.75rem', fontWeight: 600,
              fontFamily: "'Manrope', sans-serif", flexShrink: 0,
            }}>
              {typeLabel}
            </span>
            {c.source?.name && (
              <span style={{ fontSize: '0.75rem', color: '#0D9488', fontFamily: "'Manrope', sans-serif", display: 'flex', alignItems: 'center', gap: 3 }}>
                <Shield size={11} />
                {c.source.name}
              </span>
            )}
          </div>
          <p style={{
            fontFamily: "'Merriweather', serif", fontSize: '0.9375rem',
            fontWeight: 700, color: '#0F172A', lineHeight: 1.4,
            margin: '0 0 0.375rem', overflow: 'hidden', textOverflow: 'ellipsis',
            display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical',
          }}>
            {c.title ?? 'Без названия'}
          </p>
          {(c.author || c.published_at) && (
            <p style={{ fontFamily: "'Manrope', sans-serif", fontSize: '0.8125rem', color: '#64748B', margin: 0 }}>
              {[c.author, formatDate(c.published_at)].filter(Boolean).join(' · ')}
            </p>
          )}
        </div>
        <button
          className="history-delete-btn"
          onClick={(e) => { e.stopPropagation(); onRemove(bookmark.id); }}
          disabled={isRemoving}
          aria-label="Удалить из закладок"
          style={{ flexShrink: 0 }}
        >
          <X size={15} />
        </button>
      </div>

      {bookmark.bookmark?.note && (
        <p style={{
          fontFamily: "'Manrope', sans-serif", fontSize: '0.8125rem',
          color: '#6B7280', margin: '0.75rem 0 0',
          paddingTop: '0.75rem', borderTop: '1px solid #F1F5F9',
          fontStyle: 'italic',
        }}>
          📝 {bookmark.bookmark.note}
        </p>
      )}

      {c.url && (
        <div style={{ marginTop: '0.75rem' }}>
          <a
            href={c.url}
            target="_blank"
            rel="noopener noreferrer"
            onClick={(e) => e.stopPropagation()}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: '0.375rem',
              padding: '0.375rem 0.75rem', border: '1px solid #E5E7EB',
              borderRadius: '0.5rem', fontFamily: "'Manrope', sans-serif",
              fontSize: '0.75rem', fontWeight: 500, color: '#374151',
              textDecoration: 'none', transition: 'background .15s',
            }}
          >
            <ExternalLink size={12} />
            Открыть источник
          </a>
        </div>
      )}
    </div>
  );
};

const UserBookmarks = () => {
  const navigate = useNavigate();
  const [showAuthModal, setShowAuthModal] = useState(false);
  const [bookmarks, setBookmarks] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [removingIds, setRemovingIds] = useState(new Set());
  const [pagination, setPagination] = useState(null);
  const [page, setPage] = useState(1);

  useEffect(() => {
    if (!localStorage.getItem('auth_token')) navigate('/');
  }, [navigate]);

  const fetchBookmarks = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await userAPI.getBookmarks(20);
      const data = response.data;
      setBookmarks(data.data ?? []);
      setPagination({ currentPage: data.current_page, lastPage: data.last_page, total: data.total });
    } catch (err) {
      setError(err.message || 'Не удалось загрузить закладки');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchBookmarks(); }, [fetchBookmarks, page]);

  const handleRemove = async (userContentId) => {
    setRemovingIds((prev) => new Set(prev).add(userContentId));
    try {
      await userAPI.removeBookmark(userContentId);
      setBookmarks((prev) => prev.filter((b) => b.id !== userContentId));
    } catch (err) {
      setError(err.message || 'Не удалось удалить закладку');
    } finally {
      setRemovingIds((prev) => { const s = new Set(prev); s.delete(userContentId); return s; });
    }
  };

  const handleOpen = (content) => {
    if (content.id) navigate(`/article/${content.id}`, { state: { item: content } });
  };

  return (
    <div className="profile-page">
      <Navbar onAuthClick={() => setShowAuthModal(true)} />

      <main className="profile-main">
        <div className="profile-container">
          <div className="history-page-header">
            <h1 className="profile-page-title" style={{ margin: 0 }}>Сохранённые</h1>
            {pagination?.total > 0 && (
              <span style={{ fontFamily: "'Manrope', sans-serif", fontSize: '0.875rem', color: '#6B7280' }}>
                {pagination.total} материалов
              </span>
            )}
          </div>

          {error && <div className="history-error-box">⚠️ {error}</div>}

          {loading ? (
            <div className="page-loading">
              <div className="spinner" />
              <p>Загрузка…</p>
            </div>
          ) : bookmarks.length === 0 && !error ? (
            <div className="history-empty">
              <p className="history-empty-icon"><Bookmark size={48} color="#CBD5E1" /></p>
              <p className="history-empty-title">Нет сохранённых материалов</p>
              <p className="history-empty-hint">Нажмите «Сохранить» на любой карточке, чтобы добавить её сюда</p>
            </div>
          ) : (
            <ul className="history-list">
              {bookmarks.map((bm) => (
                <li key={bm.id} style={{ listStyle: 'none' }}>
                  <BookmarkCard
                    bookmark={bm}
                    isRemoving={removingIds.has(bm.id)}
                    onRemove={handleRemove}
                    onOpen={handleOpen}
                  />
                </li>
              ))}
            </ul>
          )}

          {pagination && pagination.lastPage > 1 && (
            <div className="profile-pagination">
              <button className="profile-pagination-btn" onClick={() => setPage((p) => Math.max(p - 1, 1))} disabled={pagination.currentPage === 1}>
                ← Назад
              </button>
              <span className="profile-pagination-info">
                Страница <strong>{pagination.currentPage}</strong> из <strong>{pagination.lastPage}</strong>
              </span>
              <button className="profile-pagination-btn" onClick={() => setPage((p) => Math.min(p + 1, pagination.lastPage))} disabled={pagination.currentPage === pagination.lastPage}>
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

export default UserBookmarks;
