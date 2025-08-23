<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BroadcastReportResource\Pages;
use App\Models\BroadcastMessage;
use App\Models\Group;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;

class BroadcastReportResource extends Resource
{
    protected static ?string $model = BroadcastMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'WhatsApp Service';
    protected static ?string $navigationLabel = 'Broadcast Reports';
    protected static ?string $modelLabel = 'Broadcast Report';
    protected static ?string $pluralModelLabel = 'Broadcast Reports';
    protected static ?int $navigationSort = 6;

    public static function getEloquentQuery(): Builder
    {
        // Preload relasi untuk performa & akses kolom dengan aman
        return parent::getEloquentQuery()
            ->with([
                'campaign:id,name',
                'template:id,name,category',
                'recipient:id,name,phone,group',
                'latestWebhook:id,broadcast_id,status,event_type,message_id,pricing_model,timestamp',
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('template.name')
                    ->label('Template')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('template.category')
                    ->label('Kategori')
                    ->badge()
                    ->color(fn ($state) => match (strtolower((string) $state)) {
                        'marketing' => 'success',
                        'utility' => 'info',
                        'authentication' => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('recipient.name')
                    ->label('Recipient')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('recipient.phone')
                    ->label('Phone')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('recipient.group')
                    ->label('Group (field)')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('latestWebhook.status')
                    ->label('Status Akhir')
                    ->icon(fn (?string $state) => match (strtolower((string) $state)) {
                        'accepted','sent' => 'heroicon-o-paper-airplane',
                        'delivered'       => 'heroicon-o-inbox-arrow-down',
                        'read'            => 'heroicon-o-eye',
                        'failed','undeliverable' => 'heroicon-o-x-circle',
                        default           => 'heroicon-o-clock',
                    })
                    ->color(fn (?string $state) => match (strtolower((string) $state)) {
                        'accepted','sent' => 'info',
                        'delivered','read'=> 'success',
                        'failed','undeliverable' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn ($record) => $record?->latestWebhook?->status ?? '—'),

                Tables\Columns\TextColumn::make('latestWebhook.event_type')
                    ->label('Event')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('latestWebhook.message_id')
                    ->label('Message ID')
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('latestWebhook.pricing_model')
                    ->label('Pricing')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('latestWebhook.timestamp')
                    ->label('Update Terakhir')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('campaign_id')
                    ->label('Campaign')
                    ->relationship('campaign', 'name'),

                SelectFilter::make('whatsapp_template_id')
                    ->label('Template')
                    ->relationship('template', 'name'),

                // Status dari relasi latestWebhook
                SelectFilter::make('final_status')
                    ->label('Status Akhir')
                    ->options([
                        'accepted' => 'Accepted',
                        'sent' => 'Sent',
                        'delivered' => 'Delivered',
                        'read' => 'Read',
                        'failed' => 'Failed',
                        'undeliverable' => 'Undeliverable',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (! filled($data['value'] ?? null)) return $query;
                        return $query->whereHas('latestWebhook', fn (Builder $q)
                            => $q->where('status', $data['value']));
                    }),

                // Filter berdasarkan M2M groups (recipient.groups)
                SelectFilter::make('group_id')
                    ->label('Group (pivot)')
                    ->options(fn () => Group::query()->pluck('name', 'id')->toArray())
                    ->query(function (Builder $query, array $data) {
                        if (! filled($data['value'] ?? null)) return $query;
                        return $query->whereHas('recipient.groups', fn (Builder $q)
                            => $q->where('groups.id', $data['value']));
                    }),

                // Range tanggal dibuat
                Filter::make('created_range')
                    ->label('Tanggal Dibuat')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),

                // Range timestamp webhook terakhir
                Filter::make('last_update_range')
                    ->label('Update Terakhir')
                    ->form([
                        Forms\Components\DateTimePicker::make('from')->label('Dari'),
                        Forms\Components\DateTimePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query->whereHas('latestWebhook', function (Builder $q) use ($data) {
                            $q->when($data['from'] ?? null, fn ($qq, $d) => $qq->where('timestamp', '>=', $d))
                              ->when($data['until'] ?? null, fn ($qq, $d) => $qq->where('timestamp', '<=', $d));
                        });
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Detail')
                    ->icon('heroicon-o-eye'),
                ExportAction::make()
                    ->label('Export')
                    ->icon('heroicon-o-document-arrow-up'),
            ])
            ->bulkActions([
                ExportBulkAction::make()
                    ->label('Export Terpilih')
                    ->icon('heroicon-o-document-arrow-up'),
            ])
            ->recordUrl(null); // Tetap di halaman, pakai ViewAction
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBroadcastReports::route('/'),
            'view' => Pages\ViewBroadcastReport::route('/{record}'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false; // sembunyikan dari sidebar
    }
}
