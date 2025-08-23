<?php

namespace App\Filament\Widgets;

use App\Models\BroadcastMessage;
use Filament\Widgets\ChartWidget;

class BroadcastDailyChart extends ChartWidget
{

    // Versi-agnostik: override method getter-nya
    public function getHeading(): ?string
    {
        return 'Tren Harian (Status Akhir)';
    }

    protected function getMaxHeight(): ?string
    {
        return '300px';
    }

    protected function getData(): array
    {
        $rows = BroadcastMessage::query()
            ->selectRaw('DATE(broadcasts.created_at) as d, COALESCE(w.status, "unknown") as s, COUNT(*) as c')
            ->leftJoin('whatsapp_webhooks as w', function ($join) {
                $join->on('w.broadcast_id', '=', 'broadcasts.id')
                     ->whereIn('w.id', function ($q) {
                         $q->selectRaw('MAX(id)')
                           ->from('whatsapp_webhooks')
                           ->whereColumn('broadcast_id', 'broadcasts.id');
                     });
            })
            ->groupBy('d', 's')
            ->orderBy('d')
            ->get();

        $labels = [];
        $seriesByStatus = [];

        foreach ($rows as $r) {
            $labels[$r->d] = $r->d;
            $seriesByStatus[$r->s] ??= [];
        }
        $labels = array_values($labels);

        foreach ($seriesByStatus as $status => $_) {
            $seriesByStatus[$status] = array_fill(0, count($labels), 0);
        }

        $labelIndex = array_flip($labels);
        foreach ($rows as $r) {
            $idx = $labelIndex[$r->d] ?? null;
            if ($idx !== null) {
                $seriesByStatus[$r->s][$idx] = (int) $r->c;
            }
        }

        $datasets = [];
        foreach ($seriesByStatus as $status => $data) {
            $datasets[] = [
                'label' => ucfirst($status),
                'data'  => $data,
            ];
        }

        return [
            'labels'   => $labels,
            'datasets' => $datasets,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
