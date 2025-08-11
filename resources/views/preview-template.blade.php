<div class="space-y-4 max-w-xl mx-auto">

    @foreach ($components as $component)
        @switch($component['type'])

            @case('HEADER')
                @if ($component['format'] === 'IMAGE')
                    <div>
                        <p class="text-sm font-medium text-gray-500 mb-1">Header Image:</p>
                        @if (!empty($component['example']['header_handle'][0]))
                            <img src="{{ $component['example']['header_handle'][0] }}" alt="Header Image"
                                 class="rounded shadow max-h-64">
                        @else
                            <p class="text-xs text-gray-400 italic">No image provided</p>
                        @endif
                    </div>
                @endif
                @break

            @case('BODY')
                <div>
                    <p class="text-sm font-medium text-gray-500 mb-1">Body Text:</p>
                    <div class="bg-gray-100 p-4 rounded whitespace-pre-line">
                        {!! nl2br(
                            e(
                                preg_replace_callback('/\{\{\d+\}\}/', function ($matches) use ($component) {
                                    static $i = 0;
                                    return $component['example']['body_text'][0][$i++] ?? $matches[0];
                                }, $component['text'])
                            )
                        ) !!}
                    </div>
                </div>
                @break

            @case('FOOTER')
                <div class="text-xs text-gray-400 italic border-t pt-2">
                    {{ $component['text'] }}
                </div>
                @break

            @case('BUTTONS')
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach ($component['buttons'] as $button)
                        @if ($button['type'] === 'URL')
                            <a href="{{ $button['url'] }}" target="_blank"
                               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                {{ $button['text'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
                @break

        @endswitch
    @endforeach

</div>
