<div
    x-data="{
        scrollDown() {
            const c = $refs.chat;
            if (c) c.scrollTop = c.scrollHeight;
        },
        init() {
            this.$nextTick(() => this.scrollDown());
        }
    }"
    x-on:chat-scrolldown.window="scrollDown()"
    class="flex flex-col h-[calc(100vh-10rem)]"
>
    {{-- Header --}}
    <div class="px-4 py-3 border-b bg-white flex items-center justify-between">
        <div class="font-semibold text-gray-700">
            WhatsApp Chat — {{ $this->phone }}
        </div>
        <div class="text-xs text-gray-500">
            *Enter untuk kirim, atau tombol Send
        </div>
    </div>

    {{-- Chat area --}}
    <div
        x-ref="chat"
        class="flex-1 overflow-y-auto px-4 py-3 space-y-2 bg-gray-50"
        wire:loading.class="opacity-70"
        wire:target="sendMessage,data.image,data.sticker"
    >
        @forelse($this->messages as $m)
            <div class="flex {{ $m['out'] ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[80%]">
                    <div class="px-3 py-2 rounded-2xl shadow
                        {{ $m['out'] ? 'bg-[#DCF8C6] rounded-br-none' : 'bg-white rounded-bl-none' }}">

                        {{-- TEXT --}}
                        @if(($m['type'] ?? '') === 'text')
                            <div class="whitespace-pre-line text-[15px] leading-relaxed">
                                {!! nl2br(e($m['text'] ?? '')) !!}
                            </div>

                        {{-- MEDIA TYPES --}}
                        @elseif(in_array($m['type'] ?? '', ['image','sticker','video','document','audio']))
                            {{-- IMAGE / STICKER --}}
                            @if(!empty($m['media']) && in_array($m['type'], ['image','sticker']))
                                <img
                                    src="{{ $m['media'] }}"
                                    alt="{{ strtoupper($m['type']) }}"
                                    class="rounded-lg max-w-xs sm:max-w-md h-auto"
                                    loading="lazy"
                                    onerror="this.replaceWith(Object.assign(document.createElement('div'), {className: 'text-sm text-red-600', textContent: 'Media tidak tersedia'}));"
                                />
                            {{-- VIDEO --}}
                            @elseif(!empty($m['media']) && $m['type'] === 'video')
                                <video
                                    class="rounded-lg max-w-xs sm:max-w-md h-auto"
                                    controls
                                >
                                    <source src="{{ $m['media'] }}">
                                </video>
                            {{-- AUDIO --}}
                            @elseif(!empty($m['media']) && $m['type'] === 'audio')
                                <audio class="w-full" controls>
                                    <source src="{{ $m['media'] }}">
                                </audio>
                            {{-- DOCUMENT --}}
                            @elseif(!empty($m['media']) && $m['type'] === 'document')
                                <a
                                    href="{{ $m['media'] }}"
                                    target="_blank"
                                    class="text-blue-600 underline text-sm"
                                >
                                    Lihat Dokumen
                                </a>
                            @else
                                <div class="text-sm text-gray-600">
                                    {{ strtoupper($m['type'] ?? '-') }} — media tidak tersedia
                                </div>
                            @endif

                            {{-- Caption / text pelengkap --}}
                            @if(!empty($m['text']))
                                <div class="mt-1 whitespace-pre-line text-[15px] leading-relaxed">
                                    {!! nl2br(e($m['text'])) !!}
                                </div>
                            @endif

                        {{-- FALLBACK --}}
                        @else
                            <div class="text-sm text-gray-600">{{ strtoupper($m['type'] ?? 'TEXT') }}</div>
                            @if(!empty($m['text']))
                                <div class="mt-1 whitespace-pre-line text-[15px] leading-relaxed">
                                    {!! nl2br(e($m['text'])) !!}
                                </div>
                            @endif
                        @endif

                        <div class="text-[11px] text-gray-500 mt-1 text-right">
                            {{ $m['time'] ?? '' }}
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center text-gray-500 text-sm py-10">
                Belum ada percakapan.
            </div>
        @endforelse

        {{-- Loading bubble saat proses kirim/upload --}}
        <div wire:loading wire:target="sendMessage,data.image,data.sticker" class="flex justify-end">
            <div class="px-3 py-2 rounded-2xl shadow bg-[#DCF8C6] rounded-br-none text-sm text-gray-600">
                Mengirim…
            </div>
        </div>
    </div>

    {{-- Composer --}}
    <div class="border-t bg-white p-3">
        <form wire:submit.prevent="sendMessage" class="flex flex-col gap-3">
            {{-- Form Filament (Text, Image, Sticker, tombol Send) --}}
            {{ $this->form }}
        </form>

        {{-- Error state Livewire upload (opsional) --}}
        @error('data.image')
            <div class="text-red-600 text-sm mt-2">{{ $message }}</div>
        @enderror
        @error('data.sticker')
            <div class="text-red-600 text-sm mt-2">{{ $message }}</div>
        @enderror
    </div>
</div>
