# Точка Знаний

**Национальный семантический граф знаний и агрегатор верифицированных научно-образовательных ресурсов**

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?style=flat-square&logo=laravel)](https://laravel.com/)
[![React](https://img.shields.io/badge/React-19-61DAFB?style=flat-square&logo=react)](https://react.dev/)
[![MySQL](https://img.shields.io/badge/MySQL-8.4-4479A1?style=flat-square&logo=mysql)](https://www.mysql.com/)
[![Apache Jena](https://img.shields.io/badge/Apache%20Jena-Fuseki-D22561?style=flat-square)](https://jena.apache.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=flat-square&logo=docker)](https://www.docker.com/)

---

## О проекте

Россия десятилетиями накапливала научное и культурное наследие. Миллионы единиц хранения в музеях, архивах и репозиториях (eLIBRARY.RU, КиберЛенинка). Но сегодня мы оказались в ловушке **«оцифрованного хаоса»**. Данные превратились в цифровые копии документов, разбросанных по порталам. Они подходят для чтения человеком, но остаются невидимыми для машин.

**Точка Знаний** — это переход от традиционных «информационных систем» (поиск по ключевым словам) к «интеллектуальным системам» (поиск по смыслу и связям). 

Вместо простого индексирования текста, система выстраивает **онтологические связи**: `Автор → Научная школа → Публикация → Исторический период → Музейный экспонат`. Это не просто агрегатор, а эталонная среда для обучения отечественных LLM (RAG-системы) на верифицированных данных, исключающая галлюцинации ИИ.

---

## Ключевые архитектурные решения

- **Гибридный поиск:** Сочетание полнотекстового поиска (MySQL FULLTEXT + морфологическая нормализация Snowball) и семантического обогащения через SPARQL-запросы к RDF-графу.
- **Визуализация графа знаний:** Интерактивная сеть семантических связей на базе `react-force-graph` вместо скучных списков.
- **Мультиисточниковая агрегация:** Конвейер нормализации данных из OAI-PMH (КиберЛенинка), REST API (музеи) и веб-скрапинга в единый стандарт Dublin Core.
- **Отказоустойчивость (Resilience):** Модульная архитектура. При падении семантического слоя (Fuseki) реляционный поиск продолжает работать.
- **Готовность к RAG:** Данные хранятся в машиночитаемом RDF/OWL формате, что позволяет использовать граф как внешнюю память (Grounding) для GigaChat и YandexGPT.
- **Экспорт данных:** Поддержка CSV, JSON-LD, RDF/XML.

---

## Технологический стек и Архитектура

Система построена по модульному принципу (Layered MVC) с разделением на реляционный (операционные данные) и семантический (граф знаний) контуры.

### Backend (Серверная часть)
- **PHP 8.5** + **Laravel 11** (RESTful API, Sanctum, оркестрация конвейера нормализации).
- **Парсеры:** OAI-PMH 2.0 Client, REST API Client, Scraper.
- **Scheduler:** Фоновая обработка парсинга и синхронизации.

### Frontend (Клиентская часть)
- **React 19** + **Vite 5.4** (SPA, компонентная архитектура).
- **react-force-graph** (визуализация RDF-триплов в интерактивный граф).
- **Адаптивная вёрстка:** Поддержка устройств от 320px, светлая и тёмная темы.

### Базы данных и Семантический слой
- **MySQL 8.4** — реляционное хранилище (3НФ), полнотекстовый индекс, транзакционная логика.
- **Apache Jena Fuseki** — семантическое RDF-хранилище (TDB2), выполнение сложных SPARQL 1.1 запросов.
- **Онтология:** OWL 2 (Dublin Core, Schema.org), разработана в Protégé 5.x (5 классов, 8 свойств).

### Инфраструктура
- **Docker & Docker Compose** (Nginx, PHP-FPM, MySQL, Fuseki).
- **CI/CD Ready:** Готово к деплою на любые VPS или Kubernetes.

---

## Интерфейс и Визуализация

### 1. Публичный интерфейс (Исследователь / Студент)
Минималистичный дизайн с фокусом на контенте. Строгая цветовая палитра (белый, серый, фиолетовый, черный) для длительной академической работы.

| Главная страница | Темная тема | Адаптивность |
| :---: | :---: | :---: |
| ![Главная (Светлая)](docs/screenshots/01_main_light.png) | ![Главная (Темная)](docs/screenshots/02_main_dark.png) | ![Адаптив](docs/screenshots/03_responsive.png) |
| *Строка поиска и популярные материалы* | *Альтернативное отображение* | *Мобильные устройства* |

| Авторизация | Уведомления | Результаты поиска |
| :---: | :---: | :---: |
| ![Вход](docs/screenshots/04_auth.png) | ![Успех](docs/screenshots/05_login_success.png) | ![Поиск](docs/screenshots/17_search_basic.png) |
| *Форма входа и регистрации* | *UX: Уведомление об успешном входе* | *Базовый вид выдачи* |

| Фильтрация | Карточки | Карточка материала |
| :---: | :---: | :---: |
| ![Фильтры](docs/screenshots/18_search_filters.png) | ![Альт. вид](docs/screenshots/19_search_alt.png) | ![Карточка](docs/screenshots/20_material_card.png) |
| *Тип ресурса, статус, год, язык* | *Альтернативное отображение* | *Метаданные, теги, ссылки* |

### 2. Семантический граф (Knowledge Graph)
Переход от «поиска по словам» к «поиску по мысли». Визуализация связей между сущностями.

| Граф связей (Статика) | Граф связей (Интерактив) |
| :---: | :---: |
| ![Граф](docs/screenshots/21_graph_static.png) | ![Граф Интерактив](docs/screenshots/22_graph_interactive.png) |
| *Семантические связи (RDF-триплы)* | *Навигация по узлам графа* |

### 3. Административная панель и Модерация
Инструментарий для управления источниками, верификации данных и контроля семантического слоя.

| Админ-панель | Модерация контента | Отклонение материала |
| :---: | :---: | :---: |
| ![Админка](docs/screenshots/06_admin_panel.png) | ![Модерация](docs/screenshots/07_moderation.png) | ![Отклонение](docs/screenshots/08_reject_modal.png) |

| Редактирование | Источники данных | Запуск парсинга |
| :---: | :---: | :---: |
| ![Редактирование](docs/screenshots/09_edit_material.png) | ![Источники](docs/screenshots/10_sources.png) | ![Парсинг](docs/screenshots/11_parse_dialog.png) |

| Настройки OAI-PMH | SPARQL-редактор | Управление RDF |
| :---: | :---: | :---: |
| ![OAI-PMH](docs/screenshots/12_oai_pmh.png) | ![SPARQL](docs/screenshots/13_sparql_editor.png) | ![RDF](docs/screenshots/14_rdf_management.png) |
| *Конфигурация протокола* | *Выполнение запросов к Fuseki* | *Инициализация и синхронизация* |

| Пользователи | История парсинга |
| :---: | :---: |
| ![Юзеры](docs/screenshots/15_users.png) | ![Логи](docs/screenshots/16_parse_logs.png) |
| *Ролевая модель (RBAC)* | *Статусы и количество записей* |

---

## Планы развития

Текущий прототип успешно агрегирует данные и доказывает работоспособность гибридной архитектуры. Впереди — масштабирование до промышленного уровня:

- **Масштабирование поиска:** Интеграция **Elasticsearch / OpenSearch** для субсекундного отклика на миллионах записей.
- **Переход на PostgreSQL:** Миграция реляционного слоя для лучшей поддержки JSONB.
- **Расширение онтологии:** Увеличение количества классов до 50+ (геопространственные и временные связи).
- **Подключение 10+ источников:** Нотация РФ, Госкаталог Музейного фонда РФ, региональные репозитории.
- **Интеграция с LLM (RAG):** Подключение GigaChat и YandexGPT для генерации ответов на основе верифицированного графа.

---

## **Copyright & License**

© 2026 Анна Гершпигель. All Rights Reserved.

This software, including its source code, architecture, and UI/UX designs, is the exclusive property of the author. Access to this private repository is granted strictly for evaluation and portfolio review purposes.

**RESTRICTIONS:**
* You may view the code and documentation.
* You **MAY NOT** copy, modify, distribute, sublicense, or create derivative works based on this software.
* You **MAY NOT** use any part of this code (including architectural patterns, SPARQL queries, or React components) in commercial or open-source projects without explicit written permission.
* Forking or downloading the repository for purposes other than local evaluation is prohibited.

For more details, please see the full [LICENSE](LICENSE) file. Unauthorized use of this software is a violation of copyright laws and international treaties.
