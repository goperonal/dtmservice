<?php

namespace App\Filament\Widgets;

use App\Models\BroadcastMessage;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BroadcastKpis extends BaseWidget
{
    // SEBELUM: protected static ?string $heading = 'Ringkasan KPI';
    protected ?string $heading = 'Ringkasan KPI';

    protected function getStats(): array
    {
        $base = BroadcastMessage::query()->with('latestWebhook');

        $total = (clone $base)->count();
        $sent = (clone $base)->whereHas('latestWebhook', fn ($q) => $q->whereIn('status', ['accepted','sent']))->count();
        $delivered = (clone $base)->whereHas('latestWebhook', fn ($q) => $q->where('status','delivered'))->count();
        $read = (clone $base)->whereHas('latestWebhook', fn ($q) => $q->where('status','read'))->count();
        $failed = (clone $base)->whereHas('latestWebhook', fn ($q) => $q->whereIn('status',['failed','undeliverable']))->count();

        $delivRate = $total ? round($delivered / $total * 100, 1) : 0.0;
        $readRate = $total ? round($read / $total * 100, 1) : 0.0;

        return [
            Stat::make('Total Broadcast', number_format($total)),
            Stat::make('Sent', number_format($sent)),
            Stat::make('Delivered', number_format($delivered))->description("Rate: {$delivRate}%"),
            Stat::make('Read', number_format($read))->description("Rate: {$readRate}%"),
            Stat::make('Failed', number_format($failed)),
        ];
    }
}
