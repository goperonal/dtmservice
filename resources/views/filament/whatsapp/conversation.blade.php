<div
    x-data="{
        scrollDown(){ const c=$refs.chat; if(c){ c.scrollTop=c.scrollHeight } },
        init(){ this.$nextTick(()=>this.scrollDown()); },
    }"
    x-on:chat-scrolldown.window="scrollDown()"
    class="flex flex-col h-[calc(100vh-10rem)]"
>
    {{-- Chat area --}}
    <div x-ref="chat" class="flex-1 overflow-y-auto px-4 py-3 space-y-2 bg-gray-50">
        @forelse($this->messages as $m)
            <div class="flex {{ $m['out'] ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[80%]">
                    <div class="px-3 py-2 rounded-2xl shadow
                        {{ $m['out'] ? 'bg-[#DCF8C6] rounded-br-none' : 'bg-white rounded-bl-none' }}">
                        @if($m['type'] === 'text')
                            <div class="whitespace-pre-line text-[15px] leading-relaxed">
                                {!! nl2br(e($m['text'])) !!}
                            </div>
                        @else
                            <div class="text-sm text-gray-600">{{ strtoupper($m['type'] ?? '-') }}</div>
                            @if(!empty($m['text']))
                                <div class="mt-1 whitespace-pre-line text-[15px] leading-relaxed">
                                    {!! nl2br(e($m['text'])) !!}
                                </div>
                            @endif
                        @endif
                        <div class="text-[11px] text-gray-500 mt-1 text-right">{{ $m['time'] }}</div>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center text-gray-500 text-sm py-10">Belum ada percakapan.</div>
        @endforelse
    </div>

    {{-- Composer --}}
    <div class="border-t bg-white p-3">
        <form wire:submit.prevent="sendMessage" class="flex gap-2">
            {{ $this->form }}
        </form>
    </div>
</div>
