import { useState, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { Shield, Bookmark, ExternalLink } from 'lucide-react';
import { userAPI } from '../services/api';
import './ContentGrid.css';

const TYPE_LABELS = {
  article: 'Статья',
  video: 'Видео',
  course: 'Курс',
  documentation: 'Документация',
  book: 'Книга',
};

const normalizeItem = (item) => ({
  id: item.id ?? null,
  title: item.title ?? '',
  description: item.description ?? '',
  sourceName: typeof item.source === 'object' && item.source !== null
    ? (item.source.name ?? '')
    : (item.sourceName ?? item.source ?? ''),
  type: item.type ?? 'article',
  date: item.published_at ?? item.date ?? '',
  author: item.author ?? '',
  url: item.url ?? null,
  externalId: item.external_id ?? null,
});

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  try {
    return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
  } catch { return dateStr; }
};

const BookmarkButton = ({ contentId }) => {
  const [state, setState] = useState('idle');
  const isAuth = Boolean(localStorage.getItem('auth_token'));
  const isMock = contentId === null;

  const handleClick = useCallback(async (e) => {
    e.stopPropagation();
    if (!isAuth) { alert('Войдите в аккаунт, чтобы сохранять материалы.'); return; }
    if (isMock) return;
    setState('loading');
    try {
      await userAPI.addBookmark(contentId);
      setState('saved');
      setTimeout(() => setState('idle'), 2000);
    } catch {
      setState('error');
      setTimeout(() => setState('idle'), 2000);
    }
  }, [contentId, isAuth, isMock]);

  const styleMap = {
    idle:    { background: '#F1F5F9', color: '#64748B' },
    loading: { background: '#F1F5F9', color: '#64748B' },
    saved:   { background: '#DCFCE7', color: '#16A34A' },
    error:   { background: '#FEE2E2', color: '#DC2626' },
  };

  const labelMap = {
    idle: 'Сохранить',
    loading: '…',
    saved: 'Сохранено',
    error: 'Ошибка',
  };

  return (
    <button
      className="cg-bookmark-btn"
      onClick={handleClick}
      disabled={state === 'loading' || isMock}
      style={styleMap[state]}
      title={isMock ? 'Недоступно для демо-данных' : 'Добавить в закладки'}
    >
      <Bookmark size={14} style={{ fill: state === 'saved' ? 'currentColor' : 'none' }} />
      {labelMap[state]}
    </button>
  );
};

const PaginationControls = ({ pagination, onPageChange }) => {
  if (!pagination || pagination.last_page <= 1) return null;
  const { current_page: cur, last_page: last } = pagination;
  return (
    <div className="cg-pagination">
      <button className="cg-pagination-btn" onClick={() => onPageChange(cur - 1)} disabled={cur === 1}>
        ← Назад
      </button>
      <span className="cg-pagination-info">
        Страница <strong>{cur}</strong> из <strong>{last}</strong>
      </span>
      <button className="cg-pagination-btn" onClick={() => onPageChange(cur + 1)} disabled={cur === last}>
        Вперёд →
      </button>
    </div>
  );
};

const ContentCard = ({ rawItem, viewMode }) => {
  const item = normalizeItem(rawItem);
  const typeLabel = TYPE_LABELS[item.type] ?? item.type;
  const hasLink = Boolean(item.id);
  const meta = [item.author, formatDate(item.date)].filter(Boolean).join(' · ');

  const cardLink = hasLink ? `/article/${item.id}` : null;
  const cardState = hasLink ? { item: rawItem, fromSearch: true } : undefined;

  const BadgeRow = () => (
    <div className="cg-badge-row">
      <span className="cg-type-badge">{typeLabel}</span>
      {item.sourceName && (
        <span className="cg-source-badge">
          <Shield size={12} />
          {item.sourceName}
        </span>
      )}
    </div>
  );

  const Actions = () => (
    <div className="cg-actions">
      {item.url && (
        <a
          href={item.url}
          target="_blank"
          rel="noopener noreferrer"
          className="cg-source-link"
          onClick={(e) => e.stopPropagation()}
        >
          <ExternalLink size={14} />
          Источник
        </a>
      )}
      <BookmarkButton contentId={item.id} />
    </div>
  );

  if (viewMode === 'list') {
    return (
      <div className={`cg-list-card${hasLink ? ' clickable' : ''}`}>
        {cardLink && <Link to={cardLink} state={cardState} className="cg-stretched-link" aria-label={item.title} />}
        <div className="cg-list-main">
          <BadgeRow />
          <h3 className="cg-title">{item.title}</h3>
          {meta && <p className="cg-meta">{meta}</p>}
          {item.description && <p className="cg-desc list-clamp">{item.description}</p>}
        </div>
        <div className="cg-list-aside cg-actions-wrap">
          {item.externalId && <code className="cg-ext-id">{item.externalId}</code>}
          <Actions />
        </div>
      </div>
    );
  }

  return (
    <div className={`cg-card${hasLink ? ' clickable' : ''}`}>
      {cardLink && <Link to={cardLink} state={cardState} className="cg-stretched-link" aria-label={item.title} />}
      <BadgeRow />
      <h3 className="cg-title">{item.title}</h3>
      {meta && <p className="cg-meta">{meta}</p>}
      {item.description && <p className="cg-desc">{item.description}</p>}
      <div className="cg-footer cg-actions-wrap">
        {item.externalId ? <code className="cg-ext-id">{item.externalId}</code> : <span />}
        <Actions />
      </div>
    </div>
  );
};

const ContentGrid = ({ items = null, pagination = null, onPageChange = null, viewMode = 'grid' }) => {
  if (!items) return null;
  return (
    <div>
      <div className={viewMode === 'grid' ? 'cg-grid' : 'cg-list'}>
        {items.map((rawItem, idx) => (
          <ContentCard
            key={rawItem.id ?? `item-${idx}`}
            rawItem={rawItem}
            viewMode={viewMode}
          />
        ))}
      </div>
      {pagination && onPageChange && <PaginationControls pagination={pagination} onPageChange={onPageChange} />}
    </div>
  );
};

export default ContentGrid;
