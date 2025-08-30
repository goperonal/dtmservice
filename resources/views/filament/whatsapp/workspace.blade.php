{{-- Grid: 20% / 60% / 20% --}}
<div
    x-data="{
        ver: $wire.entangle('messagesVersion'),
        scrollDown(){ this.$refs.bottom?.scrollIntoView({behavior:'auto', block:'end'}) },
        init(){ this.$nextTick(() => this.scrollDown()) }
    }"
    x-effect="ver; $nextTick(() => scrollDown())"
    x-on:chat-scrolldown.window="scrollDown()"
    style="display:grid; grid-template-columns: 20% 60% 20%; gap:16px; height:calc(100vh - 7rem); min-height:0;"
>
    {{-- ====== Kolom 1: Threads ====== --}}
    <div style="display:flex; flex-direction:column; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; min-height:0;">
        <div style="padding:10px 12px; border-bottom:1px solid #e5e7eb; background:#fff; font-weight:600;">Threads</div>

        <div style="flex:1; overflow:auto;" wire:poll.15s>
            @forelse($this->threads as $t)
                <button
                    wire:click="selectThread('{{ $t['phone'] }}')"
                    style="width:100%; text-align:left; padding:10px 12px; border-bottom:1px solid #f1f5f9; background:{{ $this->activePhone===$t['phone']?'#f8fafc':'#fff' }};"
                >
                    <div style="display:flex; gap:8px; align-items:flex-start;">
                        <div style="flex:1;">
                            <div style="font-weight:600; color:#111827;">{{ $t['display_name'] }}</div>
                            <div style="font-size:12px; color:#6b7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                {{ $t['summary'] }}
                            </div>
                            <div style="font-size:11px; color:#6b7280; margin-top:2px;">{{ $t['last_at'] }}</div>
                        </div>

                        @if(($t['unread'] ?? 0) > 0)
                            <span style="display:inline-flex; align-items:center; padding:2px 6px; border-radius:9999px; font-size:12px; font-weight:600; background:#fee2e2; color:#991b1b;">
                                {{ $t['unread'] }}
                            </span>
                        @endif
                    </div>
                </button>
            @empty
                <div style="padding:12px; font-size:14px; color:#6b7280;">Belum ada percakapan.</div>
            @endforelse
        </div>
    </div>

    {{-- ====== Kolom 2: Chat ====== --}}
    <div style="display:flex; flex-direction:column; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; min-height:0;">
        <div style="padding:10px 12px; border-bottom:1px solid #e5e7eb; background:#fff;">
            <div style="font-weight:600; color:#374151;">{{ $this->activePhone ?: 'Pilih thread…' }}</div>
        </div>

        <div
            x-ref="chat"
            style="flex:1; overflow:auto; background:#f8fafc; padding:12px; display:flex; flex-direction:column; gap:8px; min-height:0;"
            wire:key="messages-{{ $this->activePhone }}"
        >
            @forelse($this->messages as $m)
                <div style="display:flex; {{ $m['out'] ? 'justify-content:flex-end;' : 'justify-content:flex-start;' }}">
                    <div style="max-width:80%;">
                        <div style="
                            padding:10px 12px;
                            border-radius:16px;
                            box-shadow:0 1px 2px rgba(0,0,0,.06);
                            {{ $m['out'] ? 'background:#DCF8C6; border-bottom-right-radius:6px;' : 'background:#fff; border-bottom-left-radius:6px;' }}
                        ">
                            @php
                                $type    = $m['type'] ?? 'text';
                                // akan terisi jika Workspace menambahkan 'template_name'
                                // kalau tidak, fallback ke text (yang sekarang sudah “Template: {nama}” dari model)
                                $tplName = $m['template_name'] ?? data_get($m, 'template.name');
                            @endphp

                            {{-- TEMPLATE --}}
                            @if($type === 'template')
                                <div style="white-space:pre-line; font-size:15px; line-height:1.5;">
                                    {{-- $m['text'] datang dari $w->text_body accessor → "Template: <nama>" --}}
                                    {{ $m['text'] ?? 'Template' }}
                                </div>

                            {{-- TEXT --}}
                            @elseif($type === 'text')
                                <div style="white-space:pre-line; font-size:15px; line-height:1.5;">
                                    {!! nl2br(e($m['text'] ?? '')) !!}
                                </div>

                            {{-- MEDIA --}}
                            @elseif(in_array($type, ['image','sticker','video','document','audio']))
                                @if(!empty($m['media']) && in_array($type, ['image','sticker']))
                                    <img src="{{ $m['media'] }}" alt="{{ strtoupper($type) }}"
                                         style="border-radius:8px; max-width:100%; height:auto;" loading="lazy" />
                                @elseif(!empty($m['media']) && $type === 'video')
                                    <video style="border-radius:8px; max-width:100%; height:auto;" controls>
                                        <source src="{{ $m['media'] }}">
                                    </video>
                                @elseif(!empty($m['media']) && $type === 'audio')
                                    <audio style="width:100%;" controls>
                                        <source src="{{ $m['media'] }}">
                                    </audio>
                                @elseif(!empty($m['media']) && $type === 'document')
                                    <a href="{{ $m['media'] }}" target="_blank" style="color:#2563eb; text-decoration:underline; font-size:14px;">
                                        Lihat Dokumen
                                    </a>
                                @else
                                    <div style="font-size:13px; color:#6b7280;">{{ strtoupper($type) }} — media tidak tersedia</div>
                                @endif

                                @if(!empty($m['text']))
                                    <div style="margin-top:4px; white-space:pre-line; font-size:15px; line-height:1.5;">
                                        {!! nl2br(e($m['text'])) !!}
                                    </div>
                                @endif
                            @endif

                            <div style="font-size:11px; color:#6b7280; margin-top:4px; text-align:right;">
                                {{ $m['time'] ?? '' }}
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div style="text-align:center; color:#6b7280; font-size:14px; padding:40px 0;">Pilih thread di kiri.</div>
            @endforelse

            <span x-ref="bottom"></span>
        </div>

        {{-- Composer --}}
        <div style="border-top:1px solid #e5e7eb; background:#fff; padding:10px;">
            <div
                x-data="{
                    pickImage(){ this.$refs.imginput.click() },
                    pickDoc(){ this.$refs.docinput.click() }
                }"
                style="display:flex; gap:8px; align-items:center;"
            >
                <button type="button" @click.prevent="pickImage()" title="Kirim gambar"
                        style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:8px; border:1px solid #e5e7eb; background:#fff;">
                    {{-- icon image --}}
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M3 8a2 2 0 012-2h2l1-1h6l1 1h2a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M8 13l2.5-2.5L14 12l3-3 3 3"/>
                    </svg>
                </button>

                <button type="button" @click.prevent="pickDoc()" title="Kirim dokumen"
                        style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:8px; border:1px solid #e5e7eb; background:#fff;">
                    {{-- icon doc --}}
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M21 12.79V7a2 2 0 00-2-2h-5.79M16 21a5 5 0 01-5-5V5a3 3 0 116 0v10a3 3 0 11-6 0V7"/>
                    </svg>
                </button>

                <input type="text"
                       wire:model.defer="text"
                       placeholder="Tulis pesan… (Enter untuk kirim)"
                       x-on:keydown.enter.prevent="$wire.sendMessage()"
                       style="flex:1; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:14px; background:#fff;">

                <button type="button"
                        wire:click="sendMessage"
                        @disabled($isSending)
                        style="display:inline-flex; align-items:center; justify-content:center; height:36px; padding:0 14px; border-radius:8px; background:#2563eb; color:#fff; font-weight:600;">
                    @if(!$isSending)
                        Send
                    @else
                        <span style="display:inline-flex; align-items:center; gap:8px;">
                            <span style="width:14px;height:14px;border:2px solid #bfdbfe;border-top-color:#fff;border-radius:9999px;animation:spin 1s linear infinite"></span>
                            Sending…
                        </span>
                    @endif
                </button>

                {{-- hidden file inputs --}}
                <input type="file" x-ref="imginput" class="hidden" accept="image/*" wire:model="image">
                <input type="file" x-ref="docinput" class="hidden"
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,application/*,text/plain,image/*"
                       wire:model="document">
            </div>

            <div wire:loading wire:target="image,document" style="margin-top:6px; font-size:12px; color:#6b7280;">
                Menyiapkan file…
            </div>
        </div>
    </div>

    {{-- ====== Kolom 3: Customer info ====== --}}
    <div style="display:flex; flex-direction:column; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; min-height:0;">
        <div style="padding:10px 12px; border-bottom:1px solid #e5e7eb; background:#fff; font-weight:600;">Customer Info</div>

        <div style="padding:12px; display:flex; flex-direction:column; gap:12px;">
            <div>
                <label style="display:block; font-size:14px; font-weight:600; color:#374151; margin-bottom:4px;">Phone</label>
                <input type="text" value="{{ $this->activePhone }}" disabled
                       style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:14px; background:#f9fafb;">
            </div>

            <div>
                <label style="display:block; font-size:14px; font-weight:600; color:#374151; margin-bottom:4px;">Name</label>
                <input type="text" wire:model.defer="rec_name" placeholder="Nama kontak"
                       style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:14px;">
            </div>

            <div>
                <label style="display:block; font-size:14px; font-weight:600; color:#374151; margin-bottom:4px;">Notes</label>
                <textarea rows="8" wire:model.defer="rec_notes" placeholder="Catatan…"
                          style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:14px;"></textarea>
            </div>

            <button type="button"
                    wire:click="saveRecipient"
                    @disabled($isSaving)
                    style="display:inline-flex; align-items:center; justify-content:center; width:100%;
                           background:#2563eb; color:#fff; font-weight:600; border-radius:8px; padding:10px;">
                @if(!$isSaving)
                    Save
                @else
                    <span style="display:inline-flex; align-items:center; gap:8px;">
                        <span style="width:14px;height:14px;border:2px solid #bfdbfe;border-top-color:#fff;border-radius:9999px;animation:spin 1s linear infinite"></span>
                        Saving…
                    </span>
                @endif
            </button>
        </div>
    </div>

    {{-- ====== MODAL KONFIRMASI KIRIM FILE ====== --}}
    @if($confirmSend)
        <div style="position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:70; display:flex; align-items:center; justify-content:center;">
            <div style="width:540px; max-width:92vw; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.25);">
                <div style="padding:14px 16px; border-bottom:1px solid #f1f5f9; font-weight:700;">
                    Kirim {{ strtoupper($pendingType ?? '') }}?
                </div>

                <div style="padding:14px 16px; display:flex; gap:14px; align-items:flex-start;">
                    @if(($pendingType ?? null) === 'image' && $pendingPreview)
                        <div style="width:160px; height:160px; background:#fafafa; border:1px solid #e5e7eb; border-radius:8px; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                            <img src="{{ $pendingPreview }}" style="max-width:100%; max-height:100%; object-fit:contain;">
                        </div>
                    @else
                        <div style="width:160px; height:160px; border:1px dashed #d1d5db; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#6b7280;">
                            {{ strtoupper($pendingType ?? '') }}
                        </div>
                    @endif

                    <div style="flex:1;">
                        <div style="font-weight:600;">{{ $pendingName }}</div>
                        @if($pendingSize)<div style="font-size:12px; color:#6b7280; margin-top:2px;">{{ $pendingSize }}</div>@endif
                        @if(($text ?? '') !== '')
                            <div style="margin-top:8px; font-size:14px; color:#374151;">
                                <span style="font-weight:600;">Caption:</span> {{ $text }}
                            </div>
                        @endif
                    </div>
                </div>

                <div style="padding:12px 16px; display:flex; gap:8px; justify-content:flex-end; background:#f9fafb; border-top:1px solid #f1f5f9;">
                    <button type="button" wire:click="cancelPendingUpload"
                            style="height:36px; padding:0 14px; border-radius:8px; border:1px solid #e5e7eb; background:#fff;">
                        Batal
                    </button>
                    <button type="button"
                            wire:click="confirmAndSend"
                            @disabled($isSending)
                            style="height:36px; padding:0 14px; border-radius:8px; background:#2563eb; color:#fff; font-weight:600;">
                        @if(!$isSending)
                            Kirim
                        @else
                            <span style="display:inline-flex; align-items:center; gap:8px;">
                                <span style="width:14px;height:14px;border:2px solid #bfdbfe;border-top-color:#fff;border-radius:9999px;animation:spin 1s linear infinite"></span>
                                Mengirim…
                            </span>
                        @endif
                    </button>
                </div>
            </div>
        </div>
    @endif

    <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
</div>
