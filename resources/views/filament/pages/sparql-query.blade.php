<x-filament-panels::page>
    {{-- Форма ввода запроса --}}
    <x-filament::section>
        <x-slot name="heading">Редактор запроса</x-slot>

        <form wire:submit="execute">
            {{ $this->form }}

            <div class="mt-4 flex gap-3">
                <x-filament::button type="submit" icon="heroicon-o-play" wire:loading.attr="disabled">
                    <span wire:loading.remove>Выполнить запрос</span>
                    <span wire:loading>Выполняется...</span>
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-trash"
                    wire:click="clear"
                >
                    Очистить
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    {{-- Блок результатов (показывается только после выполнения запроса) --}}
    @if($executed)
        <x-filament::section class="mt-4">
            <x-slot name="heading">
                Результаты
                @if(!empty($results))
                    <span class="ml-2 text-sm font-normal text-gray-500 dark:text-gray-400">
                        {{ count($results) }} {{ count($results) === 1 ? 'запись' : (count($results) < 5 ? 'записи' : 'записей') }}
                    </span>
                @endif
            </x-slot>

            @if($error)
                <div class="rounded-lg bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800 p-4">
                    <div class="flex items-start gap-3">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5 text-danger-600 dark:text-danger-400 mt-0.5 shrink-0"/>
                        <div>
                            <p class="font-medium text-danger-700 dark:text-danger-300">Ошибка выполнения запроса</p>
                            <pre class="mt-2 text-sm text-danger-600 dark:text-danger-400 whitespace-pre-wrap break-all">{{ $error }}</pre>
                        </div>
                    </div>
                </div>

            @elseif(empty($results))
                <div class="flex flex-col items-center justify-center py-12 text-gray-400 dark:text-gray-500">
                    <x-heroicon-o-circle-stack class="h-10 w-10 mb-3"/>
                    <p class="text-sm">Результаты не найдены</p>
                    <p class="text-xs mt-1">Возможно, RDF-граф пуст или запрос не совпадает ни с одним триплетом</p>
                </div>

            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="w-full text-sm divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                @foreach($columns as $col)
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        {{ $col }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach($results as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                                    @foreach($row as $cell)
                                        <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300 max-w-xs">
                                            @if(str_starts_with($cell, 'http'))
                                                <a href="{{ $cell }}"
                                                   target="_blank"
                                                   class="text-primary-600 dark:text-primary-400 hover:underline truncate block max-w-xs"
                                                   title="{{ $cell }}">
                                                    {{ $cell }}
                                                </a>
                                            @else
                                                <span class="block truncate" title="{{ $cell }}">{{ $cell }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif

    {{-- Справка по часто используемым PREFIX-ам --}}
    <x-filament::section class="mt-4" collapsible collapsed>
        <x-slot name="heading">Справка: стандартные префиксы</x-slot>
        <div class="font-mono text-xs text-gray-600 dark:text-gray-400 space-y-1">
            <p>PREFIX rdf:    &lt;http://www.w3.org/1999/02/22-rdf-syntax-ns#&gt;</p>
            <p>PREFIX rdfs:   &lt;http://www.w3.org/2000/01/rdf-schema#&gt;</p>
            <p>PREFIX owl:    &lt;http://www.w3.org/2002/07/owl#&gt;</p>
            <p>PREFIX xsd:    &lt;http://www.w3.org/2001/XMLSchema#&gt;</p>
            <p>PREFIX dc:     &lt;http://purl.org/dc/elements/1.1/&gt;</p>
            <p>PREFIX schema: &lt;https://schema.org/&gt;</p>
            <p>PREFIX edu:    &lt;http://knowledge-aggregator.loc/ontology#&gt;</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
