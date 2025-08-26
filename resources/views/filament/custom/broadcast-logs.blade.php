@php
    use Carbon\Carbon;

    // Helper: format epoch (UTC) -> WIB
    $formatWib = function ($rawJson, $fallback = null, $fmt = 'Y-m-d H:i:s') {
        $arr = [];
        if (is_string($rawJson)) {
            $arr = json_decode($rawJson, true) ?: [];
        } elseif (is_array($rawJson)) {
            $arr = $rawJson;
        }

        $epoch = $arr['timestamp'] ?? null;

        // Kalau timestamp epoch tersedia (WhatsApp kirim detik UTC)
        if (is_numeric($epoch)) {
            try {
                return Carbon::createFromTimestamp((int) $epoch, 'UTC')
                    ->setTimezone('Asia/Jakarta')
                    ->format($fmt) . ' WIB';
            } catch (\Throwable $e) {
                // fallback ke bawah
            }
        }

        // Fallback: kalau $fallback sudah string tanggal
        if (!empty($fallback)) {
            try {
                // Asumsikan fallback masih UTC (atau server time) -> paksa ke WIB supaya konsisten
                return Carbon::parse($fallback, 'UTC')
                    ->setTimezone('Asia/Jakarta')
                    ->format($fmt) . ' WIB';
            } catch (\Throwable $e) {
                return (string) $fallback;
            }
        }

        return '-';
    };

    // Helper: warna badge status sederhana
    $statusColor = function ($status) {
        return match (strtolower((string) $status)) {
            'sent'      => '#0ea5e9', // cyan
            'delivered' => '#22c55e', // green
            'read'      => '#6366f1', // indigo
            'failed'    => '#ef4444', // red
            default     => '#6b7280', // gray
        };
    };
@endphp

<div style="max-height: calc(100vh - 200px); display: flex; flex-direction: column; height: auto;">
    <div style="flex: 1; overflow-y: auto; padding: 1rem;">
        @forelse ($logs as $log)
            @php
                $status = $log['status'] ?? '-';
                $timeWib = $formatWib($log['raw'] ?? null, $log['timestamp'] ?? null, 'd M Y H:i:s');
                $badge   = $statusColor($status);
            @endphp

            <div style="border: 1px solid #ddd; padding: 0.75rem; border-radius: 0.5rem; margin-bottom: 1rem; background: white;">
                <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.25rem;">
                    <strong>Status:</strong>
                    <span style="display:inline-block; padding:.125rem .5rem; border-radius:999px; font-size:.75rem; color:white; background: {{ $badge }};">
                        {{ $status }}
                    </span>
                </div>

                <div><strong>Time:</strong> {{ $timeWib }}</div>
                <div><strong>Recipient:</strong> {{ $log['recipient_id'] ?? '-' }}</div>

                <pre style="background: #f8f9fa; padding: 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; overflow-x: auto; white-space: pre-wrap;">
{{ $log['raw'] ?? '' }}
                </pre>
            </div>
        @empty
            <p style="color: #6c757d;">No logs found for this broadcast.</p>
        @endforelse
    </div>
</div>
