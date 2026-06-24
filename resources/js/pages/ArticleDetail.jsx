import { useState, useEffect } from 'react';
import { useParams, useLocation, useNavigate } from 'react-router-dom';
import { Shield, ExternalLink, Download, Bookmark, Share2, ChevronDown, ArrowLeft, Network, Check } from 'lucide-react';
import Navbar from '../components/layout/Navbar';
import AuthModal from '../components/AuthModal';
import GraphModal from '../components/GraphModal';
import { contentAPI, userAPI } from '../services/api';
import './ArticleDetail.css';

const TYPE_LABELS = { article: 'Статья', video: 'Видео', course: 'Курс', documentation: 'Документация', book: 'Книга' };
const LANG_LABELS = { ru: 'Русский', en: 'English' };

const formatDate = (d) => {
  if (!d) return '';
  try { return new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }); }
  catch { return d; }
};

const ArticleDetail = () => {
  const { id } = useParams();
  const { state } = useLocation();
  const navigate = useNavigate();

  const [resource, setResource] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [activeTab, setActiveTab] = useState('abstract');
  const [exportOpen, setExportOpen] = useState(false);
  const [showAuthModal, setShowAuthModal] = useState(false);
  const [bookmarkState, setBookmarkState] = useState('idle');
  const [showGraph, setShowGraph] = useState(false);
  const [shareCopied, setShareCopied] = useState(false);

  useEffect(() => {
    const stateItem = state?.item;
    // Используем данные из state только если id совпадает (переход с поиска)
    if (stateItem?.id === parseInt(id, 10)) {
      setResource(stateItem);
      setLoading(false);
      setError(null);
      return;
    }
    // Иначе всегда запрашиваем с API (переход с графа, прямая ссылка, новая вкладка)
    setResource(null);
    setLoading(true);
    setError(null);
    contentAPI.getById(id)
      .then(r => {
        const item = r?.data ?? r;
        if (item?.id) setResource(item);
        else setError('Материал не найден или недоступен.');
      })
      .catch(() => setError('Материал не найден или недоступен.'))
      .finally(() => setLoading(false));
  }, [id]); // eslint-disable-line

  const handleBookmark = async () => {
    if (!localStorage.getItem('auth_token')) { setShowAuthModal(true); return; }
    if (!resource?.id) return;
    setBookmarkState('loading');
    try { await userAPI.addBookmark(resource.id); setBookmarkState('saved'); }
    catch { setBookmarkState('idle'); }
  };

  const fromSearch = Boolean(state?.fromSearch);

  const handleBack = () => {
    if (window.history.length > 1) navigate(-1);
    else navigate('/');
  };

  const handleShare = () => {
    navigator.clipboard?.writeText(window.location.href).then(() => {
      setShareCopied(true);
      setTimeout(() => setShareCopied(false), 2000);
    });
  };

  if (loading) return (
    <div className="ad-page">
      <Navbar onAuthClick={() => setShowAuthModal(true)} />
      <main className="ad-main">
        <div className="ad-loading">
          <div className="spinner" style={{ marginBottom: '1rem' }} />
          <p>Загрузка материала…</p>
        </div>
      </main>
      {showAuthModal && <AuthModal onClose={() => setShowAuthModal(false)} />}
    </div>
  );

  if (error || !resource) return (
    <div className="ad-page">
      <Navbar onAuthClick={() => setShowAuthModal(true)} />
      <main className="ad-main">
        <div className="ad-error">
          <p style={{ fontSize: '3rem', marginBottom: '1rem' }}>📄</p>
          <h1 style={{ fontFamily: "'Merriweather', serif", fontSize: '1.5rem', fontWeight: 700, color: '#374151', marginBottom: '1rem' }}>
            {error ?? 'Материал не найден'}
          </h1>
          <button className="ad-action-btn ad-action-primary" style={{ display: 'inline-flex', width: 'auto' }} onClick={handleBack}>
            Вернуться назад
          </button>
        </div>
      </main>
      {showAuthModal && <AuthModal onClose={() => setShowAuthModal(false)} />}
    </div>
  );

  const typeLabel = TYPE_LABELS[resource.type] ?? resource.type;
  const sourceName = typeof resource.source === 'object' ? resource.source?.name : resource.sourceName;

  const metaFields = [
    { key: 'ID', val: resource.id },
    { key: 'Тип', val: typeLabel },
    { key: 'Источник', val: sourceName },
    { key: 'Идентификатор', val: resource.external_id, mono: true },
    { key: 'URL', val: resource.url, link: true },
    { key: 'Язык', val: LANG_LABELS[resource.language] ?? resource.language },
    { key: 'Дата', val: formatDate(resource.published_at ?? resource.date) },
  ].filter(f => f.val);

  return (
    <div className="ad-page">
      <Navbar onAuthClick={() => setShowAuthModal(true)} />

      <main className="ad-main">
        <div className="ad-container">
          <button className="ad-back-btn" onClick={handleBack}>
            <ArrowLeft size={16} />
            {fromSearch ? 'Назад к результатам' : 'Назад'}
          </button>

          <div className="ad-layout">
            {/* Основной контент */}
            <article className="ad-content">
              <div className="ad-card">
                {/* Badges */}
                <div className="ad-badge-row">
                  <span className="ad-type-badge">{typeLabel}</span>
                  {sourceName && (
                    <span className="ad-source-badge">
                      <Shield size={14} />
                      {sourceName}
                    </span>
                  )}
                </div>

                {/* Title */}
                <h1 style={{ fontFamily: "'Merriweather', serif", fontSize: '2rem', fontWeight: 700, color: '#0F172A', lineHeight: 1.3, marginBottom: '1.5rem' }}>
                  {resource.title}
                </h1>

                {/* Meta row */}
                <div className="ad-meta-row">
                  {resource.author && (
                    <div>
                      <div className="ad-meta-label">Автор</div>
                      <div className="ad-meta-value">{resource.author}</div>
                    </div>
                  )}
                  {(resource.published_at || resource.date) && (
                    <div>
                      <div className="ad-meta-label">Опубликовано</div>
                      <div className="ad-meta-value">{formatDate(resource.published_at ?? resource.date)}</div>
                    </div>
                  )}
                  {resource.language && (
                    <div>
                      <div className="ad-meta-label">Язык</div>
                      <div className="ad-meta-value">{LANG_LABELS[resource.language] ?? resource.language}</div>
                    </div>
                  )}
                </div>

                {/* Tabs */}
                <div className="ad-tabs">
                  {['abstract', 'metadata'].map(tab => (
                    <button key={tab} className={`ad-tab ${activeTab === tab ? 'active' : ''}`} onClick={() => setActiveTab(tab)}>
                      {tab === 'abstract' ? 'Аннотация' : 'Метаданные'}
                    </button>
                  ))}
                </div>

                {/* Tab content */}
                {activeTab === 'abstract' && (
                  <div>
                    {resource.description ? (
                      <p style={{ fontFamily: "'Merriweather', serif", fontSize: '1rem', lineHeight: 1.8, color: '#1E293B' }}>
                        {resource.description}
                      </p>
                    ) : (
                      <p style={{ color: '#9CA3AF', fontStyle: 'italic' }}>Аннотация отсутствует</p>
                    )}

                    {Array.isArray(resource.tags) && resource.tags.length > 0 && (
                      <div>
                        <div className="ad-meta-label" style={{ marginTop: '1.5rem', paddingTop: '1.5rem', borderTop: '1px solid #E5E7EB' }}>
                          Теги
                        </div>
                        <div className="ad-tags">
                          {resource.tags.map((tag, i) => <span key={i} className="ad-tag">{tag}</span>)}
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {activeTab === 'metadata' && (
                  <div className="ad-metadata-list">
                    {metaFields.map((field, i) => (
                      <div key={i} className="ad-metadata-row">
                        <div className="ad-metadata-key">{field.key}</div>
                        <div>
                          {field.link ? (
                            <a href={field.val} target="_blank" rel="noopener noreferrer"
                              style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: '0.875rem', color: '#4F46E5', wordBreak: 'break-all' }}>
                              {field.val}
                            </a>
                          ) : (
                            <span style={{
                              fontFamily: field.mono ? "'JetBrains Mono', monospace" : "'Manrope', sans-serif",
                              fontSize: '0.875rem', color: '#0F172A', wordBreak: 'break-all',
                            }}>
                              {String(field.val)}
                            </span>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </article>

            {/* Сайдбар */}
            <aside className="ad-sidebar">
              <div className="ad-sidebar-sticky">
                <div className="ad-sidebar-card">
                  <div className="ad-sidebar-title">Действия</div>
                  <div className="ad-actions">
                    {resource.url && (
                      <a href={resource.url} target="_blank" rel="noopener noreferrer" className="ad-action-btn ad-action-primary">
                        <ExternalLink size={16} />
                        Открыть источник
                      </a>
                    )}

                    <div className="ad-export-wrap">
                      <button className="ad-action-btn ad-action-secondary" onClick={() => setExportOpen(!exportOpen)}>
                        <Download size={16} />
                        Экспорт
                        <ChevronDown size={16} style={{ marginLeft: 'auto' }} />
                      </button>
                      {exportOpen && (
                        <div className="ad-export-dropdown">
                          {['CSV', 'JSON-LD', 'RDF/XML'].map(f => (
                            <button key={f} className="ad-export-item" onClick={() => setExportOpen(false)}>{f}</button>
                          ))}
                        </div>
                      )}
                    </div>

                    <button
                      className="ad-action-btn"
                      onClick={handleBookmark}
                      disabled={bookmarkState === 'loading' || bookmarkState === 'saved'}
                      style={{
                        background: bookmarkState === 'saved' ? '#DCFCE7' : '#F1F5F9',
                        color: bookmarkState === 'saved' ? '#16A34A' : '#374151',
                      }}
                    >
                      <Bookmark size={16} style={{ fill: bookmarkState === 'saved' ? 'currentColor' : 'none' }} />
                      {bookmarkState === 'saved' ? 'Сохранено' : 'Сохранить'}
                    </button>

                    <button className="ad-action-btn ad-action-secondary" onClick={() => setShowGraph(true)}>
                      <Network size={16} />
                      Семантические связи
                    </button>

                    <button
                      className="ad-action-btn ad-action-secondary"
                      onClick={handleShare}
                      style={shareCopied ? { background: '#DCFCE7', color: '#16A34A' } : undefined}
                    >
                      {shareCopied ? <Check size={16} /> : <Share2 size={16} />}
                      {shareCopied ? 'Скопировано!' : 'Поделиться'}
                    </button>
                  </div>
                </div>

                {(resource.external_id || resource.language) && (
                  <div className="ad-sidebar-card">
                    <div className="ad-sidebar-title">Метаданные</div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                      {resource.external_id && (
                        <div>
                          <div className="ad-meta-label">Идентификатор</div>
                          <code style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: '0.875rem', color: '#0F172A', wordBreak: 'break-all' }}>
                            {resource.external_id}
                          </code>
                        </div>
                      )}
                      {resource.language && (
                        <div>
                          <div className="ad-meta-label">Язык</div>
                          <div className="ad-meta-value">{LANG_LABELS[resource.language] ?? resource.language}</div>
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>
            </aside>
          </div>
        </div>
      </main>

      {showAuthModal && <AuthModal onClose={() => setShowAuthModal(false)} />}
      {showGraph && (
        <GraphModal
          onClose={() => setShowGraph(false)}
          resourceId={resource.id}
          resourceTitle={resource.title}
        />
      )}
    </div>
  );
};

export default ArticleDetail;
