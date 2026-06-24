/**
 * Компонент поисковой строки с фильтрами
 *
 * Предоставляет форму полнотекстового поиска с фильтрацией по:
 * — типу материала (article, book, documentation, video, course),
 * — источнику (динамически загружается с GET /api/sources),
 * — диапазону годов публикации,
 * — языку (ru / en).
 *
 * При сабмите формирует объект фильтров и вызывает onSearch(query, apiFilters).
 * Логика выполнения HTTP-запроса делегирована в Home.jsx.
 * Источники кешируются в состоянии компонента на время жизни страницы.
 *
 * @module components/SearchBar
 */

import React, { useState, useEffect, useCallback } from 'react';
import { sourcesAPI } from '../services/api';
import './SearchBar.css';

// ── Константы ────────────────────────────────────────────────────────────────

/**
 * Опции типов материалов
 *
 * @type {Array<{value: string, label: string}>}
 */
const TYPE_OPTIONS = [
  { value: '', label: 'Все типы' },
  { value: 'article', label: 'Статья' },
  { value: 'book', label: 'Книга' },
  { value: 'documentation', label: 'Документация' },
  { value: 'video', label: 'Видео' },
  { value: 'course', label: 'Курс' },
];

/**
 * Опции диапазонов годов публикации
 * value кодирует диапазон; yearFrom/yearTo передаются в API как числа
 *
 * @type {Array<{value: string, label: string, yearFrom: number|null, yearTo: number|null}>}
 */
const YEAR_RANGE_OPTIONS = [
  { value: '', label: 'Любой год', yearFrom: null, yearTo: null },
  { value: '2020-2026', label: '2020–2026', yearFrom: 2020, yearTo: 2026 },
  { value: '2010-2019', label: '2010–2019', yearFrom: 2010, yearTo: 2019 },
  { value: '2000-2009', label: '2000–2009', yearFrom: 2000, yearTo: 2009 },
  { value: 'before2000', label: 'До 2000', yearFrom: null, yearTo: 1999 },
];

/**
 * Опции языков материалов
 *
 * @type {Array<{value: string, label: string}>}
 */
const LANG_OPTIONS = [
  { value: '', label: 'Все языки' },
  { value: 'ru', label: 'Русский' },
  { value: 'en', label: 'English' },
];

// ── Компонент ────────────────────────────────────────────────────────────────

/**
 * Компонент поисковой строки
 *
 * @param {object}   props
 * @param {Function} props.onSearch    — колбэк (query: string, apiFilters: object) => void
 * @param {boolean}  [props.isSearching=false] — флаг активного поиска (блокирует кнопку)
 * @returns {JSX.Element}
 */
const SearchBar = ({ onSearch, isSearching = false }) => {
  /** @type {[string, Function]} текст поискового запроса */
  const [query, setQuery] = useState('');

  /** @type {[string, Function]} сообщение об ошибке валидации */
  const [error, setError] = useState('');

  /** @type {[object, Function]} выбранные значения фильтров UI */
  const [filters, setFilters] = useState({
    type: '',
    sourceId: '',
    yearRange: '',
    lang: '',
  });

  /** @type {[Array<{id: number, name: string, slug: string}>, Function]} список источников */
  const [sources, setSources] = useState([]);

  /**
   * Загружает список активных источников с API при монтировании компонента
   */
  useEffect(() => {
    sourcesAPI
      .getList()
      .then((response) => {
        if (response.success) {
          setSources(response.data);
        }
      })
      .catch(() => {
        // Недоступность источников — не критичная ошибка, фильтр просто не появится
      });
  }, []);

  /**
   * Обновляет значение одного фильтра по ключу
   *
   * @param {string} key   — ключ фильтра (type, sourceId, yearRange, lang)
   * @param {string} value — новое значение
   */
  const handleFilterChange = useCallback((key, value) => {
    setFilters((prev) => ({ ...prev, [key]: value }));
  }, []);

  /**
   * Обрабатывает сабмит формы
   *
   * Валидирует длину запроса, формирует объект API-параметров
   * (разворачивая yearRange в year_from/year_to) и вызывает props.onSearch.
   *
   * @param {React.FormEvent<HTMLFormElement>} e — событие формы
   */
  const handleSubmit = useCallback(
    (e) => {
      e.preventDefault();

      const trimmed = query.trim();
      if (trimmed.length < 2) {
        setError('Запрос должен содержать не менее 2 символов');
        return;
      }
      setError('');

      const selectedRange = YEAR_RANGE_OPTIONS.find((opt) => opt.value === filters.yearRange);

      /** @type {object} параметры для GET /api/search */
      const apiFilters = {
        type: filters.type || undefined,
        source_id: filters.sourceId ? Number(filters.sourceId) : undefined,
        year_from: selectedRange?.yearFrom ?? undefined,
        year_to: selectedRange?.yearTo ?? undefined,
        lang: filters.lang || undefined,
      };

      onSearch(trimmed, apiFilters);
    },
    [query, filters, onSearch],
  );

  return (
    <form onSubmit={handleSubmit} className="search-bar-container" role="search">
      {/* Строка ввода */}
      <div className="search-input-wrapper">
        <input
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Введите тему, автора или ключевое слово…"
          className="search-input"
          aria-label="Поисковый запрос"
          disabled={isSearching}
        />
        <button
          type="submit"
          className="search-button"
          aria-label="Найти"
          disabled={isSearching}
        >
          {isSearching ? '⏳' : '🔍'}
        </button>
      </div>

      {error && (
        <p className="search-error" role="alert">
          {error}
        </p>
      )}

      {/* Фильтры */}
      <div className="search-filters">
        {/* Тип */}
        <div className="filter-group">
          <label htmlFor="filter-type">Тип:</label>
          <select
            id="filter-type"
            className="search-filter"
            value={filters.type}
            onChange={(e) => handleFilterChange('type', e.target.value)}
          >
            {TYPE_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </div>

        {/* Источник (динамический) */}
        {sources.length > 0 && (
          <div className="filter-group">
            <label htmlFor="filter-source">Источник:</label>
            <select
              id="filter-source"
              className="search-filter"
              value={filters.sourceId}
              onChange={(e) => handleFilterChange('sourceId', e.target.value)}
            >
              <option value="">Все источники</option>
              {sources.map((src) => (
                <option key={src.id} value={src.id}>
                  {src.name}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Год */}
        <div className="filter-group">
          <label htmlFor="filter-year">Год:</label>
          <select
            id="filter-year"
            className="search-filter"
            value={filters.yearRange}
            onChange={(e) => handleFilterChange('yearRange', e.target.value)}
          >
            {YEAR_RANGE_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </div>

        {/* Язык */}
        <div className="filter-group">
          <label htmlFor="filter-lang">Язык:</label>
          <select
            id="filter-lang"
            className="search-filter"
            value={filters.lang}
            onChange={(e) => handleFilterChange('lang', e.target.value)}
          >
            {LANG_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </div>
      </div>
    </form>
  );
};

export default SearchBar;
