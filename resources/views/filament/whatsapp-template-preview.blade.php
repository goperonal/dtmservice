@php
    // --- Config preview ---
    // default: live preview (bukan phone frame)
    $phoneFrame = $phoneFrame ?? false;

    // --- Helper: render WhatsApp markdown -> HTML aman + pertahankan newline ---
    $renderWa = function (?string $text): string {
        $raw = (string) ($text ?? '');
        $raw = str_replace(["\r\n", "\r"], "\n", $raw); // normalisasi newline
        $s = e($raw);                                   // escape dulu (hindari XSS)

        // URL -> link
        $s = preg_replace('/(https?:\/\/[^\s<]+)/i', '<a href="$1" target="_blank" class="text-blue-600 underline">$1</a>', $s);

        // code block (```...```) sebelum inline (`...`)
        $s = preg_replace('/```(.*?)```/s', '<code style="white-space:pre-wrap">$1</code>', $s);
        // inline code (`...`)
        $s = preg_replace('/`(.+?)`/s', '<code>$1</code>', $s);

        // *tebal* / _miring_ / ~coret~
        $s = preg_replace('/\*(.+?)\*/s', '<strong>$1</strong>', $s);
        $s = preg_replace('/_(.+?)_/s', '<em>$1</em>', $s);
        $s = preg_replace('/~(.+?)~/s', '<del>$1</del>', $s);

        // highlight placeholder {{n}}
        $s = preg_replace('/\{\{\s*\d+\s*\}\}/', '<span class="bg-yellow-100 px-1 rounded">$0</span>', $s);

        // newline -> <br>
        return nl2br($s, false);
    };
@endphp

{{-- Wrapper: force modal scroll to TOP saat dibuka + pilih layout (phone / live) --}}
<div
  x-data
   x-init="
    $nextTick(() => {
      const win =
        $el.closest('.fi-modal-window') ||
        $el.closest('[role=dialog]') ||
        document.querySelector('.fi-modal-window');

      const content =
        win?.querySelector('.fi-modal-content') ||
        win?.querySelector('[data-dialog-description]') ||
        win;

      // batasi tinggi modal + scroll di dalam
      if (content) {
        content.style.maxHeight = '80vh';   // ↔️ ubah ke 70–85vh sesuai selera
        content.style.overflowY = 'auto';
      }

      // opsional: tempelkan modal ke atas biar tidak ‘mengambang’ di tengah
      if (win) win.style.alignItems = 'flex-start';

      // pastikan mulai dari atas
      content?.scrollTo({ top: 0, behavior: 'instant' });
    })
  "
  class="{{ $phoneFrame ? 'w-full flex justify-center' : '' }}"
>
  <div class="{{ $phoneFrame
        ? 'w-[360px] sm:w-[380px] max-w-full rounded-[24px] border shadow-md overflow-hidden bg-white'
        : 'rounded-xl border bg-white shadow-sm p-4 space-y-3' }}">

    {{-- Bar atas --}}
    @if($phoneFrame)
      <div class="bg-[#128C7E] text-center py-2 text-xs font-medium">WhatsApp Preview</div>
    @else
      <div class="font-semibold">Live Preview</div>
    @endif

    {{-- Area isi --}}
    <div class="{{ $phoneFrame ? 'p-3 space-y-3 max-h-[70vh] overflow-y-auto' : '' }}">

      {{-- Header --}}
      @if ($headerType === 'text' && filled($headerText))
        <div class="{{ $phoneFrame ? 'text-[13px]' : 'text-sm' }} font-semibold">{!! $renderWa($headerText) !!}</div>
      @elseif ($headerType === 'image')
        @if (!empty($headerImageUrl))
          <div class="w-full">
            <img
              src="{{ $headerImageUrl }}"
              alt="Header Image"
              class="rounded-lg {{ $phoneFrame ? 'max-h-40 w-full object-cover' : 'max-h-48 object-contain w-full' }}"
            >
          </div>
        @else
          <div class="{{ $phoneFrame ? 'text-[11px]' : 'text-xs' }} text-gray-500">Upload gambar untuk melihat pratinjau.</div>
        @endif
      @endif

      {{-- Body --}}
      <div class="{{ $phoneFrame ? 'text-[13px]' : 'text-sm' }} leading-relaxed">{!! $renderWa($bodyText) !!}</div>

      {{-- Footer --}}
      @if (filled($footerText))
        <div class="{{ $phoneFrame ? 'text-[11px]' : 'text-xs' }} text-gray-500 border-t pt-2">
          {!! $renderWa($footerText) !!}
        </div>
      @endif

      {{-- Buttons --}}
      @php
          $btns = [];
          if (isset($tplButtons)) {
              if (is_array($tplButtons)) {
                  $btns = $tplButtons;
              } elseif (is_string($tplButtons)) {
                  $btns = json_decode($tplButtons, true) ?: [];
              } elseif ($tplButtons instanceof \Illuminate\Support\Collection) {
                  $btns = $tplButtons->toArray();
              }
          }
      @endphp

      @if (!empty($btns) && is_iterable($btns))
        <div class="pt-2 flex flex-col gap-2">
          @foreach ($btns as $btn)
            @php
              $text = is_array($btn) ? ($btn['text'] ?? 'Open') : (string) $btn;
              $url  = is_array($btn) ? ($btn['url']  ?? '#')     : '#';
            @endphp
            <a
              href="{{ $url }}"
              target="_blank"
              class="inline-flex items-center justify-center rounded-lg border px-3 py-2 {{ $phoneFrame ? 'text-[12px]' : 'text-sm' }} hover:bg-gray-50"
            >
              {!! $renderWa($text) !!}
            </a>
          @endforeach
        </div>
      @endif

    </div>
  </div>
</div>
