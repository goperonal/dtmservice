<div style="max-height: calc(100vh - 200px);display: flex;flex-direction: column;height: auto;">
    <div style="flex: 1; overflow-y: auto; padding: 1rem;">
        @forelse ($logs as $log)
            <div style="border: 1px solid #ddd; padding: 0.75rem; border-radius: 0.5rem; margin-bottom: 1rem; background: white;">
                <div><strong>Status:</strong> {{ $log['status'] }}</div>
                <div><strong>Time:</strong> {{ $log['timestamp'] }}</div>
                <div><strong>Recipient:</strong> {{ $log['recipient_id'] }}</div>
                <pre style="background: #f8f9fa; padding: 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; overflow-x: auto; white-space: pre-wrap;">{{ $log['raw'] }}</pre>
            </div>
        @empty
            <p style="color: #6c757d;">No logs found for this broadcast.</p>
        @endforelse
    </div>
</div>
