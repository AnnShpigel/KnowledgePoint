/**
 * API Service — централизованный HTTP-клиент для взаимодействия с Laravel API
 *
 * Все запросы проходят через функцию request(), которая:
 * — автоматически добавляет Bearer-токен из localStorage,
 * — парсит JSON-ответ,
 * — пробрасывает ошибки с читаемым сообщением.
 *
 * @module services/api
 */

/**
 * Базовый URL API.
 * Используем относительный путь /api — работает на любом домене
 * (localhost, aggregator.loc, production) без изменения кода.
 */
const API_BASE_URL = '/api';

/**
 * Выполнить HTTP-запрос к API
 *
 * @param {string} endpoint — путь относительно API_BASE_URL (например, '/auth/login')
 * @param {RequestInit} [options={}] — опции fetch (method, body, headers и т.д.)
 * @returns {Promise<object>} — распарсенный JSON-ответ
 * @throws {Error} — если ответ не OK или сеть недоступна
 */
const request = async (endpoint, options = {}) => {
  const token = localStorage.getItem('auth_token');

  const config = {
    method: 'GET',
    headers: {
      'Content-Type': 'application/json',
      ...(token && { Authorization: `Bearer ${token}` }),
    },
    ...options,
  };

  const response = await fetch(`${API_BASE_URL}${endpoint}`, config);

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.message || `Ошибка ${response.status}`);
  }

  return response.json();
};

// ── Аутентификация ───────────────────────────────────────────────────────────

/**
 * API методы для работы с аутентификацией
 */
export const authAPI = {
  /**
   * Зарегистрировать нового пользователя
   *
   * @param {string} email — email пользователя
   * @param {string} password — пароль (минимум 5 символов)
   * @returns {Promise<{success: boolean, data: {access_token: string, user: object}}>}
   */
  register: async (email, password) => {
    const response = await request('/auth/register', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    });

    if (response.success) {
      localStorage.setItem('auth_token', response.data.access_token);
      localStorage.setItem('user', JSON.stringify(response.data.user));
    }

    return response;
  },

  /**
   * Войти в систему
   *
   * @param {string} email — email пользователя
   * @param {string} password — пароль
   * @returns {Promise<{success: boolean, data: {access_token: string, user: object}}>}
   */
  login: async (email, password) => {
    const response = await request('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    });

    if (response.success) {
      localStorage.setItem('auth_token', response.data.access_token);
      localStorage.setItem('user', JSON.stringify(response.data.user));
    }

    return response;
  },

  /**
   * Выйти из системы — удаляет токен на сервере и в localStorage
   *
   * @returns {Promise<object>}
   */
  logout: async () => {
    const response = await request('/auth/logout', { method: 'POST' });
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user');
    return response;
  },

  /**
   * Получить данные текущего авторизованного пользователя
   *
   * @returns {Promise<{success: boolean, data: object}>}
   */
  me: () => request('/auth/me'),

  /**
   * Обновить Bearer-токен (продление сессии)
   *
   * @returns {Promise<{success: boolean, data: {access_token: string}}>}
   */
  refresh: async () => {
    const response = await request('/auth/refresh', { method: 'POST' });
    if (response.success) {
      localStorage.setItem('auth_token', response.data.access_token);
    }
    return response;
  },
};

// ── Поиск и контент ──────────────────────────────────────────────────────────

/**
 * API методы для поиска материалов и получения результатов
 */
export const contentAPI = {
  /**
   * Получить материал по ID
   *
   * @param {number} id — идентификатор материала
   * @returns {Promise<{success: boolean, data: object}>}
   */
  getById: (id) => request(`/content/${id}`),

  /**
   * Получить данные семантического графа для материала
   *
   * @param {number} id — идентификатор материала
   * @returns {Promise<{success: boolean, available: boolean, nodes: object[], links: object[]}>}
   */
  getRelatedGraph: (id) => request(`/content/${id}/graph`),

  /**
   * Выполнить полнотекстовый поиск по материалам базы знаний
   *
   * Соответствует GET /api/search.
   * Для авторизованных пользователей запрос автоматически записывается
   * в историю поиска на стороне сервера.
   *
   * @param {string} query — поисковая строка (минимум 2 символа)
   * @param {object} [filters={}] — параметры фильтрации
   * @param {string} [filters.type] — тип материала: article, video, course, documentation, book
   * @param {number|null} [filters.year_from] — год публикации «от» (включительно)
   * @param {number|null} [filters.year_to] — год публикации «до» (включительно)
   * @param {string} [filters.lang] — язык: ru или en
   * @param {number|null} [filters.source_id] — идентификатор источника
   * @param {number} [filters.per_page=15] — записей на страницу
   * @param {number} [page=1] — номер страницы
   * @returns {Promise<{success: boolean, data: {items: object[], pagination: object}, meta: object}>}
   */
  search: (query, filters = {}, page = 1) => {
    const params = new URLSearchParams({ q: query, page: String(page) });

    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) {
        params.append(key, String(value));
      }
    });

    return request(`/search?${params.toString()}`);
  },
};

// ── Источники ────────────────────────────────────────────────────────────────

/**
 * API методы для работы со списком источников (публичный каталог)
 */
export const sourcesAPI = {
  /**
   * Получить список активных источников для фильтра поиска
   *
   * Не требует аутентификации. Возвращает id, name, slug каждого источника.
   *
   * @returns {Promise<{success: boolean, data: Array<{id: number, name: string, slug: string}>}>}
   */
  getList: () => request('/sources'),
};

// ── Профиль ──────────────────────────────────────────────────────────────────

/**
 * API методы для управления профилем пользователя
 */
export const profileAPI = {
  /**
   * Обновить email пользователя
   *
   * @param {string} email — новый email
   * @returns {Promise<object>}
   */
  updateEmail: async (email) => {
    const response = await request('/auth/profile/email', {
      method: 'POST',
      body: JSON.stringify({ email }),
    });

    if (response.success) {
      const user = JSON.parse(localStorage.getItem('user') ?? '{}');
      user.email = email;
      localStorage.setItem('user', JSON.stringify(user));
    }

    return response;
  },

  /**
   * Изменить пароль пользователя
   *
   * @param {string} currentPassword — текущий пароль
   * @param {string} newPassword — новый пароль
   * @returns {Promise<object>}
   */
  updatePassword: (currentPassword, newPassword) =>
    request('/auth/profile/password', {
      method: 'POST',
      body: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
    }),

  /**
   * Удалить аккаунт пользователя (необратимо)
   *
   * @param {string} password — пароль для подтверждения
   * @returns {Promise<object>}
   */
  deleteProfile: async (password) => {
    const response = await request('/auth/profile/delete', {
      method: 'POST',
      body: JSON.stringify({ password }),
    });

    if (response.success) {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('user');
    }

    return response;
  },
};

// ── Администрирование ────────────────────────────────────────────────────────

/**
 * API методы для административной панели (требуют роль admin)
 */
export const adminAPI = {
  // ── Источники ──────────────────────────────────────────────────────────────
  getSources: () => request('/admin/sources'),
  createSource: (data) => request('/admin/sources', { method: 'POST', body: JSON.stringify(data) }),
  updateSource: (id, data) => request(`/admin/sources/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  deleteSource: (id) => request(`/admin/sources/${id}`, { method: 'DELETE' }),

  // ── Контент и модерация ────────────────────────────────────────────────────
  /** @param {string} status — pending | approved | rejected */
  getContent: (status = 'pending', page = 1, perPage = 20) =>
    request(`/admin/content?status=${status}&page=${page}&per_page=${perPage}`),

  /**
   * Обновить статус (и поля) контента. Отправляет весь объект контента
   * с изменённым полем status — бэкенд требует все обязательные поля.
   * @param {number} id
   * @param {object} contentData — полный объект контента с новым status
   */
  updateContent: (id, contentData) =>
    request(`/admin/content/${id}`, { method: 'PUT', body: JSON.stringify(contentData) }),

  deleteContent: (id) => request(`/admin/content/${id}`, { method: 'DELETE' }),

  // ── Парсинг ────────────────────────────────────────────────────────────────
  getParseLogs: (page = 1) => request(`/admin/parse-logs?page=${page}`),
  triggerParse: (sourceId) => request(`/admin/sources/${sourceId}/parse`, { method: 'POST' }),
  getStats: () => request('/admin/stats'),
};

// ── Личный кабинет ───────────────────────────────────────────────────────────

/**
 * API методы для личного кабинета пользователя
 *
 * Все методы требуют аутентификации (Bearer-токен в localStorage).
 */
export const userAPI = {
  /**
   * Получить историю поиска текущего пользователя
   *
   * @param {number} [perPage=20] — количество записей на страницу
   * @returns {Promise<{success: boolean, data: object}>} — пагинированный список
   */
  getHistory: (perPage = 20) => request(`/user/history?per_page=${perPage}`),

  /**
   * Удалить одну запись из истории поиска
   *
   * @param {number} id — идентификатор записи search_history
   * @returns {Promise<object>}
   */
  deleteHistoryEntry: (id) => request(`/user/history/${id}`, { method: 'DELETE' }),

  /**
   * Очистить всю историю поиска пользователя
   *
   * @returns {Promise<object>}
   */
  clearHistory: () => request('/user/history', { method: 'DELETE' }),

  /**
   * Получить список закладок (сохранённых материалов)
   *
   * @param {number} [perPage=20] — количество записей на страницу
   * @returns {Promise<{success: boolean, data: object}>} — пагинированный список
   */
  getBookmarks: (perPage = 20) => request(`/user/bookmarks?per_page=${perPage}`),

  /**
   * Добавить материал в закладки (идемпотентно)
   *
   * @param {number} contentId — идентификатор одобренного контента
   * @param {string|null} [note=null] — необязательная заметка
   * @returns {Promise<object>}
   */
  addBookmark: (contentId, note = null) =>
    request('/user/bookmarks', {
      method: 'POST',
      body: JSON.stringify({ content_id: contentId, ...(note && { note }) }),
    }),

  /**
   * Удалить материал из закладок
   *
   * @param {number} userContentId — идентификатор записи user_contents (не content_id!)
   * @returns {Promise<object>}
   */
  removeBookmark: (userContentId) =>
    request(`/user/bookmarks/${userContentId}`, { method: 'DELETE' }),
};
