<x-filament::page>
    <div class="grid grid-cols-1 gap-3">
        {{-- CHAT BOX --}}
        <div
            id="chatScroll"
            class="h-[65vh] overflow-y-auto bg-white rounded-lg border p-3"
            wire:poll.5s="loadMessages"
            x-data
            @messages-updated.window="const el = $el; requestAnimationFrame(() => { el.scrollTop = el.scrollHeight })"
        >
            @forelse($messages as $m)
                <div class="mb-2 flex {{ $m['direction']==='out' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[70%] rounded-2xl px-3 py-2 shadow
                        {{ $m['direction']==='out' ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border' }}">
                        @if($m['type']==='text')
                            <div class="text-sm whitespace-pre-line break-words">{!! e($m['text'] ?? '') !!}</div>

                        @elseif($m['type']==='image')
                            @if($m['caption'])
                                <div class="text-sm whitespace-pre-line break-words mb-1">{!! e($m['caption']) !!}</div>
                            @endif
                            <div class="text-xs italic text-gray-500">[image id: {{ $m['imageId'] ?? '-' }}]</div>

                        @elseif($m['type']==='document')
                            @if($m['caption'])
                                <div class="text-sm whitespace-pre-line break-words mb-1">{!! e($m['caption']) !!}</div>
                            @endif
                            <div class="text-xs italic text-gray-500">[document id: {{ $m['docId'] ?? '-' }}]</div>
                        @endif

                        <div class="mt-1 flex items-center gap-2 text-[11px] text-gray-500">
                            <span>{{ $m['at'] }}</span>

                            {{-- Status kecil di bubble outbound --}}
                            @if($m['direction']==='out' && $m['status'])
                                <span>•</span>
                                <span class="inline-flex items-center gap-1">
                                    @php
                                        $label = strtoupper($m['status']);
                                        $icon = match ($label) {
                                            'SENT' => '✓',
                                            'DELIVERED' => '✓✓',
                                            'READ' => '✓✓', // bisa beda ikon kalau mau
                                            default => '•',
                                        };
                                        $css = match ($label) {
                                            'READ' => 'text-blue-500',
                                            default => 'text-gray-500',
                                        };
                                    @endphp
                                    <span class="{{ $css }}">{{ $icon }}</span>
                                    <span class="{{ $css }}">{{ strtolower($m['status']) }}</span>
                                    @if($m['status_at'])
                                        <span class="{{ $css }}">({{ $m['status_at'] }})</span>
                                    @endif
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-sm text-gray-500">Belum ada pesan.</div>
            @endforelse
        </div>

        {{-- REPLY FORM --}}
        <form wire:submit.prevent="submit" class="bg-white rounded-lg border p-3">
            {{ $this->form }}
            <div class="mt-2 flex items-center gap-2">
                <x-filament::button type="submit" color="success">Send</x-filament::button>
                <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Resources\WhatsappInboxResource::getUrl() }}">Back</x-filament::button>
            </div>
        </form>
    </div>
</x-filament::page>
