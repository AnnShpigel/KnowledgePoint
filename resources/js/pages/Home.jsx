import { useState, useRef, useCallback, useEffect } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { Search, Clock, Filter, ChevronDown, ChevronUp, Shield, Grid, List, X } from 'lucide-react';
import Navbar from '../components/layout/Navbar';
import AuthModal from '../components/AuthModal';
import ContentGrid from '../components/ContentGrid';
import { contentAPI, sourcesAPI } from '../services/api';
import './Home.css';

// ── Моковые популярные материалы ──────────────────────────────────────────────

const POPULAR_RESOURCES = [
  {
    id: 'pop-1',
    title: 'Квантовая коррекция ошибок в топологических квантовых вычислительных системах',
    description: 'Топологические квантовые вычисления представляют собой перспективный подход к созданию отказоустойчивых квантовых компьютеров. Данное комплексное исследование изучает механизмы коррекции ошибок, специально разработанные для топологических квантовых систем, с акцентом на операции энионного плетения и их устойчивость к декогеренции окружающей среды.',
    author: 'Чжан Л., Чен М., Кумар Р.',
    type: 'article',
    language: 'en',
    published_at: '2025-03-15',
    external_id: '10.1038/s41586-025-12345-6',
    url: null,
    source: { name: 'Nature Physics' },
    tags: ['Квантовые вычисления', 'Коррекция ошибок', 'Топология', 'Энионы'],
    typeLabel: 'Статья',
  },
  {
    id: 'pop-2',
    title: 'Машинное обучение для прогнозирования климата: теория и применение',
    description: 'Книга исследует применение методов машинного обучения для моделирования и прогнозирования климатических изменений. Авторы анализируют эффективность различных архитектур нейронных сетей — от рекуррентных до трансформеров — при работе с временными рядами метеорологических данных. Особое внимание уделяется интерпретируемости моделей и их применению в реальных задачах климатологии.',
    author: 'Иванов А.С., Петрова К.В.',
    type: 'book',
    language: 'ru',
    published_at: '2024-09-01',
    external_id: '978-5-00-123456-7',
    url: null,
    source: { name: 'Наука' },
    tags: ['Машинное обучение', 'Климатология', 'Нейронные сети', 'Прогнозирование'],
    typeLabel: 'Книга',
  },
  {
    id: 'pop-3',
    title: 'Достижения CRISPR-Cas9 в таргетной генной терапии',
    description: 'Последние разработки в технологии CRISPR обеспечили беспрецедентную точность редактирования генома человека. В данной статье рассматриваются клинические испытания генной терапии моногенных заболеваний, включая серповидноклеточную анемию и дистрофию Дюшенна. Авторы анализируют эффективность, безопасность и этические аспекты применения CRISPR-Cas9 в клинической практике.',
    author: 'Мартинес С., Ли Х., Патель Н., О\'Брайен К.',
    type: 'article',
    language: 'en',
    published_at: '2025-01-20',
    external_id: '10.1016/j.cell.2025.03.042',
    url: null,
    source: { name: 'Cell' },
    tags: ['CRISPR', 'Генная терапия', 'Биотехнологии', 'Геномика'],
    typeLabel: 'Статья',
  },
];

const RECENT_SEARCHES = ['Квантовые вычисления', 'Машинное обучение', 'CRISPR', 'Нейросети'];

const TYPE_FILTER_OPTIONS = [
  { value: 'article', label: 'Статьи' },
  { value: 'book', label: 'Книги' },
  { value: 'documentation', label: 'Документация' },
];

const SORT_OPTIONS = [
  { value: 'relevance', label: 'По релевантности' },
  { value: 'date_desc', label: 'Сначала новые' },
  { value: 'date_asc', label: 'Сначала старые' },
];

// ── Компонент ─────────────────────────────────────────────────────────────────

const Home = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();

  const initialQuery = searchParams.get('q') || '';
  const [inputValue, setInputValue] = useState(initialQuery);
  const [activeQuery, setActiveQuery] = useState(initialQuery);

  const [searchItems, setSearchItems] = useState(null);
  const [semanticItems, setSemanticItems] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [searching, setSearching] = useState(false);
  const [searchError, setSearchError] = useState(null);

  const [filtersExpanded, setFiltersExpanded] = useState(true);
  const [viewMode, setViewMode] = useState('grid');
  const [filters, setFilters] = useState({ type: '', yearFrom: '', yearTo: '', lang: '', sourceId: '', sort: 'relevance', verifiedOnly: false });
  const [sources, setSources] = useState([]);

  const [showAuthModal, setShowAuthModal] = useState(false);

  const lastQueryRef = useRef(initialQuery);
  const lastFiltersRef = useRef(filters);

  // Загрузка источников
  useEffect(() => {
    sourcesAPI.getList()
      .then(r => { if (r.success) setSources(r.data); })
      .catch(() => {});
  }, []);

  // Начальный поиск если URL содержит ?q=
  useEffect(() => {
    if (initialQuery) performSearch(initialQuery, filters, 1);
  }, []); // eslint-disable-line

  // Сброс состояния при навигации на / без параметра q (клик по логотипу)
  useEffect(() => {
    const q = searchParams.get('q');
    if (!q && activeQuery) {
      setActiveQuery('');
      setInputValue('');
      setSearchItems(null);
      setSemanticItems([]);
      setPagination(null);
      setSearchError(null);
    }
  }, [searchParams]); // eslint-disable-line

  // Год считается валидным только если это 4-значное число в диапазоне 1500-2030
  const parseYear = (val) => {
    if (!val || String(val).trim().length < 4) return undefined;
    const n = Number(val);
    if (isNaN(n) || n < 1500 || n > 2030) return undefined;
    return n;
  };

  const performSearch = useCallback(async (query, currentFilters, page = 1) => {
    setSearching(true);
    setSearchError(null);

    const apiFilters = {
      type:      currentFilters.type     || undefined,
      year_from: parseYear(currentFilters.yearFrom),
      year_to:   parseYear(currentFilters.yearTo),
      lang:      currentFilters.lang     || undefined,
      source_id: currentFilters.sourceId || undefined,
      sort:      currentFilters.sort     || 'relevance',
    };

    try {
      const response = await contentAPI.search(query, apiFilters, page);
      if (response.success) {
        setSearchItems(response.data.items);
        setSemanticItems(response.data.semantic_items ?? []);
        setPagination(response.data.pagination);
      }
    } catch (err) {
      setSearchError(err.message || 'Не удалось выполнить поиск. Попробуйте позже.');
      setSearchItems([]);
      setSemanticItems([]);
      setPagination(null);
    } finally {
      setSearching(false);
    }
  }, []);

  const handleSearch = useCallback((overrideQuery) => {
    const trimmed = (overrideQuery !== undefined ? overrideQuery : inputValue).trim();
    if (trimmed.length < 2) return;
    setActiveQuery(trimmed);
    setInputValue(trimmed);
    lastQueryRef.current = trimmed;
    lastFiltersRef.current = filters;
    setSearchParams({ q: trimmed });
    performSearch(trimmed, filters, 1);
  }, [inputValue, filters, setSearchParams, performSearch]);

  const handleClear = useCallback(() => {
    setActiveQuery('');
    setInputValue('');
    setSearchItems(null);
    setSemanticItems([]);
    setPagination(null);
    setSearchError(null);
    setSearchParams({});
  }, [setSearchParams]);

  const handlePageChange = useCallback((page) => {
    performSearch(lastQueryRef.current, lastFiltersRef.current, page);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, [performSearch]);

  const handleFilterChange = useCallback((key, value) => {
    const newFilters = { ...lastFiltersRef.current, [key]: value };
    lastFiltersRef.current = newFilters;
    setFilters(newFilters);
    // Для полей года не запускаем поиск пока не введены 4 цифры
    if (key === 'yearFrom' || key === 'yearTo') {
      if (value && String(value).trim().length < 4) return;
    }
    if (lastQueryRef.current) performSearch(lastQueryRef.current, newFilters, 1);
  }, [performSearch]);

  const handleTypeToggle = useCallback((typeValue) => {
    const newType = filters.type === typeValue ? '' : typeValue;
    handleFilterChange('type', newType);
  }, [filters.type, handleFilterChange]);

  const handlePopularClick = useCallback((mockItem) => {
    navigate(`/article/${mockItem.id}`, { state: { item: mockItem } });
  }, [navigate]);

  const isSearchMode = Boolean(activeQuery);

  return (
    <div className="home-page">
      <Navbar onAuthClick={() => setShowAuthModal(true)} />

      <main className="home-main">
        {!isSearchMode ? (
          /* ── Главный экран ─────────────────────────────────────────────── */
          <div className="home-container home-hero-section">
            <div className="hero-text-center">
              <h1 className="hero-title">
                Найдите проверенные<br />научные ресурсы
              </h1>
              <p className="hero-subtitle">
                Доступ к миллионам статей, книг и данных из верифицированных источников
              </p>

              <div className="hero-search-wrap">
                <Search className="hero-search-icon" size={24} />
                <input
                  type="text"
                  className="hero-search-input"
                  value={inputValue}
                  onChange={(e) => setInputValue(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                  placeholder="Введите тему, автора или DOI..."
                />
                <button className="hero-search-btn" onClick={() => handleSearch()}>
                  Найти
                </button>
              </div>

              <div className="recent-searches-row">
                <Clock size={16} color="#9CA3AF" />
                <span>Недавние запросы:</span>
                {RECENT_SEARCHES.map((s) => (
                  <button key={s} className="recent-chip" onClick={() => handleSearch(s)}>
                    {s}
                  </button>
                ))}
              </div>
            </div>

            {/* Popular resources */}
            <div>
              <h2 className="section-title">Популярные материалы</h2>
              <div className="popular-grid">
                {POPULAR_RESOURCES.map((r) => (
                  <div key={r.id} className="popular-card" onClick={() => handlePopularClick(r)}>
                    <div className="card-badge-row">
                      <span className="type-badge">{r.typeLabel}</span>
                      <span className="verified-badge">
                        <Shield size={12} />
                        Верифицирован
                      </span>
                    </div>
                    <h3 className="popular-card-title">{r.title}</h3>
                    <p className="popular-card-meta">{r.author} · {r.published_at?.slice(0, 4)}</p>
                  </div>
                ))}
              </div>
            </div>
          </div>
        ) : (
          /* ── Поисковый экран ───────────────────────────────────────────── */
          <div className="home-container search-section">
            {/* Search bar row */}
            <div className="search-bar-row">
              <div className="search-input-wrap">
                <Search className="search-input-icon" size={20} />
                <input
                  type="text"
                  className="search-input"
                  value={inputValue}
                  onChange={(e) => setInputValue(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                  placeholder="Введите тему, автора или DOI..."
                />
              </div>
              <button className="btn-primary" onClick={() => handleSearch()}>
                <Search size={18} />
                <span className="btn-label">Найти</span>
              </button>
            </div>

            {/* Filters — Figma layout */}
            <div className="filters-panel">
              <button className="filters-toggle" onClick={() => setFiltersExpanded(!filtersExpanded)}>
                <span className="filters-toggle-left">
                  <Filter size={20} color="#6B7280" />
                  Фильтры
                </span>
                {filtersExpanded ? <ChevronUp size={20} color="#6B7280" /> : <ChevronDown size={20} color="#6B7280" />}
              </button>

              {filtersExpanded && (
                <div className="filters-body">
                  <div className="filters-grid">
                    {/* Тип ресурса — чекбоксы как в Figma */}
                    <div>
                      <label className="filter-label">Тип ресурса</label>
                      <div className="filter-checkboxes">
                        {TYPE_FILTER_OPTIONS.map(opt => (
                          <label key={opt.value} className="filter-checkbox-row">
                            <input
                              type="checkbox"
                              className="filter-checkbox"
                              checked={filters.type === opt.value}
                              onChange={() => handleTypeToggle(opt.value)}
                            />
                            <span className="filter-checkbox-label">{opt.label}</span>
                          </label>
                        ))}
                      </div>
                    </div>

                    {/* Год издания */}
                    <div>
                      <label className="filter-label">Год издания</label>
                      <div className="year-range-row">
                        <input
                          type="number"
                          className="filter-input"
                          placeholder="2020"
                          value={filters.yearFrom}
                          onChange={(e) => handleFilterChange('yearFrom', e.target.value)}
                        />
                        <span className="year-dash">—</span>
                        <input
                          type="number"
                          className="filter-input"
                          placeholder="2025"
                          value={filters.yearTo}
                          onChange={(e) => handleFilterChange('yearTo', e.target.value)}
                        />
                      </div>
                    </div>

                    {/* Язык */}
                    <div>
                      <label className="filter-label">Язык</label>
                      <select
                        className="filter-select"
                        value={filters.lang}
                        onChange={(e) => handleFilterChange('lang', e.target.value)}
                      >
                        <option value="">Все языки</option>
                        <option value="ru">Русский</option>
                        <option value="en">English</option>
                      </select>
                    </div>

                    {/* Источник */}
                    {sources.length > 0 && (
                      <div>
                        <label className="filter-label">Источник</label>
                        <select
                          className="filter-select"
                          value={filters.sourceId}
                          onChange={(e) => handleFilterChange('sourceId', e.target.value)}
                        >
                          <option value="">Все источники</option>
                          {sources.map(s => (
                            <option key={s.id} value={String(s.id)}>{s.name}</option>
                          ))}
                        </select>
                      </div>
                    )}

                    {/* Сортировка */}
                    <div>
                      <label className="filter-label">Сортировка</label>
                      <select
                        className="filter-select"
                        value={filters.sort}
                        onChange={(e) => handleFilterChange('sort', e.target.value)}
                      >
                        {SORT_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                      </select>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Results header */}
            <div className="results-header">
              <div className="results-header-left">
                {!searching && searchItems !== null && (
                  <>
                    <p className="results-count">
                      <strong>{pagination?.total ?? searchItems.length}</strong>{' '}
                      результатов по запросу «{activeQuery}»
                    </p>
                    <button className="results-clear-btn" onClick={handleClear}>
                      <X size={13} />
                      Очистить
                    </button>
                  </>
                )}
                {searching && <p className="results-count">Поиск…</p>}
              </div>

              <div className="view-toggle">
                <button
                  className="view-btn"
                  onClick={() => setViewMode('list')}
                  style={{ background: viewMode === 'list' ? '#EEF2FF' : '#F1F5F9' }}
                  aria-label="Список"
                >
                  <List size={20} color={viewMode === 'list' ? '#4F46E5' : '#6B7280'} />
                </button>
                <button
                  className="view-btn"
                  onClick={() => setViewMode('grid')}
                  style={{ background: viewMode === 'grid' ? '#EEF2FF' : '#F1F5F9' }}
                  aria-label="Сетка"
                >
                  <Grid size={20} color={viewMode === 'grid' ? '#4F46E5' : '#6B7280'} />
                </button>
              </div>
            </div>

            {/* Results */}
            {searching ? (
              <div className="search-loading">
                <div className="home-spinner" />
                <p>Поиск материалов…</p>
              </div>
            ) : searchError ? (
              <div className="search-error-box">⚠️ {searchError}</div>
            ) : searchItems !== null && searchItems.length === 0 ? (
              <div className="search-empty">
                <p style={{ fontSize: '3rem', marginBottom: '1rem' }}>🔍</p>
                <p style={{ fontSize: '1.25rem', fontWeight: 600, color: '#374151', marginBottom: '0.5rem' }}>Ничего не найдено</p>
                <p style={{ color: '#6B7280' }}>Попробуйте изменить запрос или убрать часть фильтров</p>
              </div>
            ) : searchItems !== null ? (
              <>
                <ContentGrid
                  items={searchItems}
                  pagination={pagination}
                  onPageChange={handlePageChange}
                  viewMode={viewMode}
                />
                {semanticItems.length > 0 && (
                  <div style={{ marginTop: '2rem' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '1rem', paddingBottom: '0.75rem', borderBottom: '1px solid #E5E7EB' }}>
                      <span style={{ fontSize: '0.75rem', fontWeight: 600, letterSpacing: '0.05em', textTransform: 'uppercase', color: '#14B8A6' }}>Семантически связанные</span>
                      <span style={{ fontSize: '0.75rem', color: '#94A3B8' }}>из RDF-графа</span>
                    </div>
                    <ContentGrid items={semanticItems} viewMode={viewMode} />
                  </div>
                )}
              </>
            ) : null}
          </div>
        )}
      </main>

      {showAuthModal && <AuthModal onClose={() => setShowAuthModal(false)} />}
    </div>
  );
};

export default Home;
