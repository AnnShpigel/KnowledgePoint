<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ParseLog;
use App\Models\Source;
use App\Services\ParserFactory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

/**
 * Artisan-команда запуска парсера источников данных.
 *
 * Загружает записи из внешних источников (OAI-PMH, API, скрапинг)
 * и сохраняет их в таблицу contents со статусом «pending» для последующей
 * модерации администратором через административную панель.
 *
 * Сценарии использования:
 *
 * Запустить парсинг конкретного источника по ID:
 *   php artisan source:parse 1
 *
 * Запустить парсинг по slug источника:
 *   php artisan source:parse cyberleninka
 *
 * Запустить все активные источники с поддерживаемым парсером:
 *   php artisan source:parse
 *
 * Ограничить количество загружаемых записей (для тестирования):
 *   php artisan source:parse 1 --limit=50
 *
 * Пропустить скрапинг HTML-страниц (быстрый импорт только OAI-метаданных):
 *   php artisan source:parse 1 --skip-scrape
 *
 * Только обогатить уже существующие записи данными со страниц:
 *   php artisan source:parse 1 --scrape-only
 *
 * @package App\Console\Commands
 */
class ParseSource extends Command
{
    /**
     * Сигнатура команды с описанием аргументов и опций.
     *
     * @var string
     */
    protected $signature = 'source:parse
        {source? : ID или slug источника. Без аргумента — все активные источники}
        {--limit=0 : Максимум записей за один запуск (0 = без ограничений)}
        {--skip-scrape : Пропустить скрапинг HTML-страниц (только OAI-PMH метаданные)}
        {--scrape-only : Только обогатить существующие записи данными со страниц (не реализовано)}';

    /**
     * Краткое описание команды для вывода в artisan list.
     *
     * @var string
     */
    protected $description = 'Запустить парсинг источников данных (OAI-PMH и другие)';

    /**
     * Точка входа Artisan-команды.
     *
     * Определяет список источников для обработки (один или все активные),
     * запускает парсер для каждого и записывает результат в parse_logs.
     *
     * @param ParserFactory $factory Фабрика парсеров (внедряется через IoC).
     *
     * @return int Код завершения: self::SUCCESS (0) или self::FAILURE (1).
     */
    public function handle(ParserFactory $factory): int
    {
        $sourceArg = $this->argument('source');
        $limit = (int) $this->option('limit');
        $skipScrape = (bool) $this->option('skip-scrape');

        $sources = $this->resolveSources($sourceArg);

        if ($sources->isEmpty()) {
            $this->warn('Нет активных источников с поддерживаемым парсером.');
            $this->line('Добавьте источник типа «oai-pmh» через административную панель.');
            return self::FAILURE;
        }

        $overallSuccess = true;

        foreach ($sources as $source) {
            $this->newLine();
            $this->info("▶ Парсинг источника: «{$source->name}» (ID: {$source->id}, тип: {$source->type})");

            if (!$factory->supports($source)) {
                $this->warn("  Тип «{$source->type}» не поддерживается. Пропускаем.");
                continue;
            }

            $success = $this->runParser($factory, $source, $limit, $skipScrape);

            if (!$success) {
                $overallSuccess = false;
            }
        }

        $this->newLine();
        $this->info('Парсинг завершён.');

        return $overallSuccess ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Получить список источников для парсинга.
     *
     * Если передан аргумент — ищем один источник по ID или slug.
     * Без аргумента — все активные источники с поддерживаемым типом.
     *
     * @param string|null $argument Значение аргумента source из CLI.
     *
     * @return EloquentCollection<int, Source>
     */
    private function resolveSources(?string $argument): EloquentCollection
    {
        if ($argument !== null) {
            // Поиск по ID или по slug
            $query = is_numeric($argument)
                ? Source::where('id', $argument)
                : Source::where('slug', $argument);

            $source = $query->first();

            if ($source === null) {
                $this->error("Источник «{$argument}» не найден в базе данных.");
                // Возвращаем пустую Eloquent-коллекцию — handle() увидит isEmpty() и вернёт FAILURE
                return new EloquentCollection();
            }

            return new EloquentCollection([$source]);
        }

        // Все активные источники типа oai-pmh
        return Source::where('is_active', true)
            ->where('type', 'oai-pmh')
            ->get();
    }

    /**
     * Запустить парсер для одного источника с логированием в parse_logs.
     *
     * Создаёт запись ParseLog в начале с status='running',
     * обновляет её по завершении (success/failed).
     *
     * @param ParserFactory $factory    Фабрика парсеров.
     * @param Source        $source     Источник данных.
     * @param int           $limit      Ограничение на количество записей (0 = нет).
     * @param bool          $skipScrape Отключить скрапинг HTML-страниц.
     *
     * @return bool true, если парсинг завершился без критических ошибок.
     */
    private function runParser(
        ParserFactory $factory,
        Source $source,
        int $limit,
        bool $skipScrape,
    ): bool {
        // Применяем временные переопределения настроек через parse_settings
        $this->applyRuntimeOverrides($source, $limit, $skipScrape);

        // Создаём запись лога как «выполняется»
        $log = ParseLog::create([
            'source_id' => $source->id,
            'status' => 'running',
            'started_at' => Carbon::now(),
        ]);

        $parser = $factory->make($source);
        $bar = null;
        $lastTitle = '';

        try {
            $result = $parser->parse(
                source: $source,
                onProgress: function (int $total, string $title) use ($log, &$bar, &$lastTitle): void {
                    $lastTitle = $title;

                    // Инициализируем прогресс-бар при первом вызове
                    if ($bar === null) {
                        $estimated = $log->total_records ?? 0;
                        $bar = $this->output->createProgressBar($estimated ?: 0);
                        $bar->setFormat(' %current% записей | %elapsed:6s% | %message%');
                        $bar->start();
                    }

                    $bar->setMessage(mb_strimwidth($title, 0, 60, '…'));
                    $bar->advance();

                    // Периодически обновляем счётчик в БД для мониторинга через API
                    if ($total % 10 === 0) {
                        $log->update(['items_parsed' => $total]);
                    }
                },
            );

            $bar?->finish();
            $this->newLine();

            // Обновляем лог с финальной статистикой
            $log->update([
                'status' => $result->hasErrors() ? 'failed' : 'success',
                'items_parsed' => $result->total(),
                'items_new' => $result->created,
                'items_updated' => $result->updated,
                'items_skipped' => $result->skipped,
                'pages_fetched' => $result->pagesFetched,
                'total_records' => $result->totalRecords,
                'error_message' => $result->hasErrors()
                    ? implode("\n", array_slice($result->errors, 0, 10))
                    : null,
                'finished_at' => Carbon::now(),
            ]);

            // Обновляем метку последнего парсинга источника
            $source->update(['last_parsed_at' => Carbon::now()]);

            // Вывод итогов
            $this->printResult($source, $result);

            return !$result->hasErrors();
        } catch (\Throwable $e) {
            $bar?->finish();
            $this->newLine();

            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => Carbon::now(),
            ]);

            $this->error("  ✗ Критическая ошибка: {$e->getMessage()}");
            Log::error("[ParseSource] Критическая ошибка парсинга источника {$source->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Применить временные переопределения настроек парсера из аргументов CLI.
     *
     * Изменения вносятся в runtime-копию parse_settings модели (не сохраняются в БД).
     * После завершения команды модель не сохраняется, поэтому настройки не затираются.
     *
     * @param Source $source     Источник данных.
     * @param int    $limit      Лимит записей (0 = не переопределять).
     * @param bool   $skipScrape Отключить скрапинг.
     *
     * @return void
     */
    private function applyRuntimeOverrides(Source $source, int $limit, bool $skipScrape): void
    {
        $settings = $source->parse_settings ?? [];

        if ($limit > 0) {
            $settings['batch_limit'] = $limit;
            $this->line("  Лимит записей: {$limit}");
        }

        if ($skipScrape) {
            $settings['scrape_pages'] = false;
            $this->line('  Скрапинг страниц: отключён (--skip-scrape)');
        }

        // Устанавливаем в атрибут без сохранения в БД
        $source->parse_settings = $settings;
    }

    /**
     * Вывести итоговую таблицу результатов парсинга в консоль.
     *
     * @param Source                                    $source Источник данных.
     * @param \App\Services\Parsers\DTO\ParseResult     $result Результат парсинга.
     *
     * @return void
     */
    private function printResult(Source $source, \App\Services\Parsers\DTO\ParseResult $result): void
    {
        $statusIcon = $result->hasErrors() ? '⚠' : '✓';
        $this->info("  {$statusIcon} {$source->name}: {$result->summary()}");

        if ($result->hasErrors()) {
            $this->warn('  Нефатальные ошибки (первые 5):');
            foreach (array_slice($result->errors, 0, 5) as $error) {
                $this->line("    • {$error}");
            }
            if (count($result->errors) > 5) {
                $this->line('    ... и ещё ' . (count($result->errors) - 5) . ' ошибок в laravel.log');
            }
        }
    }
}
