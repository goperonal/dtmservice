<div class="space-y-4">
    @php
        $componentsData = $components ?? [];
        if (is_string($componentsData)) {
            $componentsData = json_decode($componentsData, true) ?? [];
        }
    @endphp

    @forelse($componentsData as $component)
        @if(($component['type'] ?? '') === 'BODY')
            <p class="text-gray-800">{{ $component['text'] ?? '' }}</p>
        @elseif(($component['type'] ?? '') === 'HEADER' && ($component['format'] ?? '') === 'IMAGE')
            <img src="{{ $component['example']['header_handle'] ?? '' }}" alt="Image" class="w-full rounded shadow">
        @elseif(($component['type'] ?? '') === 'HEADER' && ($component['format'] ?? '') === 'VIDEO')
            <video controls class="w-full rounded shadow">
                <source src="{{ $component['example']['header_handle'] ?? '' }}" type="video/mp4">
            </video>
        @endif
    @empty
        <p class="text-gray-400 italic">Belum ada konten.</p>
    @endforelse
</div>
