<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\RdfService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Страница интерактивного SPARQL-редактора для административной панели.
 *
 * Позволяет администратору выполнять произвольные SPARQL SELECT/ASK/CONSTRUCT/DESCRIBE
 * запросы к RDF-хранилищу Fuseki непосредственно из браузера, без использования
 * внешних инструментов (SPARQL-эндпоинт Fuseki или CLI).
 *
 * Реализована как Livewire-компонент: запрос вводится в textarea,
 * при нажатии "Выполнить" данные отправляются в RdfService::sparqlSelect(),
 * результат отображается как HTML-таблица на той же странице.
 *
 * Безопасность: INSERT/UPDATE/DELETE/DROP SPARQL-операции блокируются
 * на уровне RdfService (они доступны только через отдельный admin-эндпоинт).
 *
 * @package App\Filament\Pages
 */
class SparqlQueryPage extends Page implements HasForms
{
    use InteractsWithForms;

    /** @var string Blade-шаблон страницы. */
    protected static string $view = 'filament.pages.sparql-query';

    /** @var string|null Иконка навигации (Heroicons). */
    protected static ?string $navigationIcon = 'heroicon-o-code-bracket';

    /** @var string|null Метка пункта навигации. */
    protected static ?string $navigationLabel = 'SPARQL-запросы';

    /** @var string|null Группа навигации. */
    protected static ?string $navigationGroup = 'Семантика';

    /** @var string|null Заголовок страницы. */
    protected static ?string $title = 'SPARQL-редактор';

    /** @var int|null Порядок сортировки в группе навигации. */
    protected static ?int $navigationSort = 1;

    /**
     * Данные формы (связаны с Livewire через statePath 'data').
     *
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * Результаты последнего выполненного запроса.
     * Каждая строка — ассоциативный массив {переменная => значение}.
     *
     * @var list<list<string>>
     */
    public array $results = [];

    /**
     * Заголовки столбцов результирующей таблицы.
     *
     * @var list<string>
     */
    public array $columns = [];

    /**
     * Сообщение об ошибке последнего запроса (null — нет ошибки).
     *
     * @var string|null
     */
    public ?string $error = null;

    /**
     * Флаг: выполнялся ли хоть один запрос (управляет отображением блока результатов).
     *
     * @var bool
     */
    public bool $executed = false;

    /**
     * Инициализировать форму с примером запроса.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->form->fill([
            'query' => implode("\n", [
                'PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>',
                'PREFIX dc:   <http://purl.org/dc/elements/1.1/>',
                'PREFIX edu:  <http://knowledge-aggregator.loc/ontology#>',
                '',
                'SELECT ?resource ?title ?type',
                'WHERE {',
                '  ?resource rdf:type ?type .',
                '  ?resource dc:title  ?title .',
                '  FILTER(?type != owl:Ontology)',
                '}',
                'ORDER BY ?title',
                'LIMIT 20',
            ]),
        ]);
    }

    /**
     * Определить схему формы с полем ввода SPARQL-запроса.
     *
     * @param Form $form Объект формы Filament.
     *
     * @return Form Настроенная форма.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make('query')
                    ->label('SPARQL-запрос')
                    ->placeholder('SELECT ?s ?p ?o WHERE { ?s ?p ?o } LIMIT 10')
                    ->rows(10)
                    ->required()
                    ->extraAttributes(['class' => 'font-mono text-sm']),
            ])
            ->statePath('data');
    }

    /**
     * Выполнить SPARQL-запрос и сохранить результаты в свойства компонента.
     *
     * Вызывается Livewire при submit формы (wire:submit="execute").
     *
     * @return void
     */
    public function execute(): void
    {
        $this->validate();

        $query = trim($this->data['query'] ?? '');

        try {
            $bindings = app(RdfService::class)->sparqlSelect($query);

            if (empty($bindings)) {
                $this->results = [];
                $this->columns = [];
                $this->error = null;
                $this->executed = true;

                Notification::make()
                    ->title('Запрос выполнен — результаты не найдены')
                    ->info()
                    ->send();

                return;
            }

            // Извлекаем заголовки из первой строки
            $this->columns = array_keys($bindings[0]);

            // Извлекаем только значения (убираем обёртку {type, value})
            $this->results = array_map(
                fn (array $row): array => array_map(
                    fn (array $cell): string => $cell['value'] ?? '',
                    $row,
                ),
                $bindings,
            );

            $this->error = null;
            $this->executed = true;

            Notification::make()
                ->title('Запрос выполнен')
                ->body('Получено строк: ' . count($this->results))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->results = [];
            $this->columns = [];
            $this->executed = true;

            Notification::make()
                ->title('Ошибка SPARQL-запроса')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Сбросить результаты и очистить форму.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->results = [];
        $this->columns = [];
        $this->error = null;
        $this->executed = false;
        $this->form->fill(['query' => '']);
    }

    /**
     * Кнопки действий в заголовке страницы.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('clear')
                ->label('Очистить')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->action('clear'),
        ];
    }
}
