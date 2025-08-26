<x-filament::page>
    <div class="grid grid-cols-1 gap-3">
        <div class="h-[65vh] overflow-y-auto bg-white rounded-lg border p-3" id="chatScroll">
            @forelse($messages as $m)
                <div class="mb-2 flex {{ $m['direction']==='out' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[70%] rounded-2xl px-3 py-2 shadow
                        {{ $m['direction']==='out' ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border' }}">
                        @if($m['type']==='text')
                            <div class="text-sm whitespace-pre-line">{!! e($m['text'] ?? '') !!}</div>
                        @elseif($m['type']==='image')
                            <div class="text-xs mb-1">{{ $m['caption'] }}</div>
                            <div class="text-xs italic">[image: {{ $m['imageId'] }}]</div>
                        @elseif($m['type']==='document')
                            <div class="text-xs mb-1">{{ $m['caption'] }}</div>
                            <div class="text-xs italic">[document: {{ $m['docId'] }}]</div>
                        @endif
                        <div class="text-[11px] text-gray-500 mt-1">{{ $m['at'] }}</div>
                    </div>
                </div>
            @empty
                <div class="text-sm text-gray-500">Belum ada pesan.</div>
            @endforelse
        </div>

        <form wire:submit.prevent="submit" class="bg-white rounded-lg border p-3">
            {{ $this->form }}
            <div class="mt-2">
                <x-filament::button type="submit" color="success">Send</x-filament::button>
                <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Resources\WhatsappInboxResource::getUrl() }}">Back</x-filament::button>
            </div>
        </form>
    </div>

    <script>
        // autoscroll
        const box = document.getElementById('chatScroll');
        if (box) box.scrollTop = box.scrollHeight;
    </script>
</x-filament::page>
