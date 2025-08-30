<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BroadcastResource\Pages;
use App\Models\BroadcastMessage;
use App\Models\Campaign;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class BroadcastResource extends Resource
{
    protected static ?string $model = BroadcastMessage::class;

    protected static ?string $navigationIcon  = 'heroicon-o-list-bullet';
    protected static ?string $navigationGroup = 'WhatsApp Services';
    protected static ?int    $navigationSort  = 4;
    protected static ?string $navigationLabel = 'Broadcast List';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /**
     * Eager load supaya kolom accessor & status webhook tidak N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'campaign',                 // untuk tampilkan nama campaign / template kalau perlu
                'recipient',                // nama & nomor penerima
                'latestWebhookByWamid',     // status WA paling akurat berdasarkan message_id = wamid
                'latestWebhook',            // fallback (kalau belum ada wamid)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('recipient.name')
                    ->label('Recipient')
                    ->searchable(),

                Tables\Columns\TextColumn::make('recipient.phone')
                    ->label('Phone')
                    ->searchable()
                    ->sortable(),

                // Status akhir gabungan (prioritas WA failed/delivered/read)
                Tables\Columns\TextColumn::make('merged_status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->colors([
                        'gray'    => fn ($state) => in_array($state, [null, 'pending'], true),
                        'info'    => fn ($state) => in_array($state, ['processing', 'sent'], true),
                        'success' => fn ($state) => in_array($state, ['delivered', 'read'], true),
                        'danger'  => fn ($state) => $state === 'failed',
                        'warning' => fn ($state) => $state === 'queued',
                    ]),

                // Pesan error (kalau failed). Disembunyikan default, bisa ditoggle.
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->dateTime('d M Y H:i', 'Asia/Jakarta')
                    ->label('Sent At')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Action::make('seeLog')
                    ->label('See Log')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->modalHeading('Broadcast Webhook Logs')
                    ->modalButton('Close')
                    ->modalContent(function ($record) {
                        // Ambil logs by WAMID (paling akurat). Fallback ke broadcast_id bila belum ada wamid.
                        $query = \App\Models\WhatsappWebhook::query();

                        if (!empty($record->wamid)) {
                            $query->where('message_id', $record->wamid);
                        } else {
                            $query->where('broadcast_id', $record->id);
                        }

                        $logs = $query->orderByDesc('id')->get()->map(function ($log) {
                            $payload = is_array($log->payload)
                                ? $log->payload
                                : (json_decode($log->payload ?? '', true) ?: []);

                            // Normalisasi timestamp (Meta bisa kirim epoch string)
                            $ts = data_get($payload, 'timestamp');
                            $ts = is_numeric($ts) ? date('Y-m-d H:i:s', (int) $ts) : (string) $ts;

                            // Ambil status dari beberapa kemungkinan path
                            $status =
                                data_get($payload, 'statuses.0.status') ??
                                data_get($payload, 'status') ??
                                data_get($payload, 'messages.0.status');

                            return [
                                'status'       => $status ?: '-',
                                'timestamp'    => $ts ?: '-',
                                'recipient_id' => (string) (data_get($payload, 'recipient_id') ?? '-'),
                                'raw'          => json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),
                            ];
                        });

                        // Gunakan blade sederhana untuk list JSON log
                        return view('filament.custom.broadcast-logs', compact('logs'));
                    }),
            ])
            ->bulkActions([
                ExportBulkAction::make()
                    ->exports([
                        ExcelExport::make('selected')
                            ->fromTable()
                            ->only([
                                'campaign.name',
                                'recipient.name',
                                'recipient.phone',
                                'merged_status',   // status akhir
                                'error_message',   // pesan error kalau ada
                                'sent_at',
                            ])
                            ->modifyQueryUsing(function (Builder $query) {
                                return $query->with([
                                    'campaign', 'recipient', 'latestWebhookByWamid', 'latestWebhook',
                                ]);
                            }),
                    ])
                    ->icon('heroicon-o-document-arrow-up'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('campaign_id')
                    ->label('Campaign')
                    ->options(
                        Campaign::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                    ),
                // Filter berdasarkan status "internal queue" (bukan WA status),
                // berguna saat melihat pending/failed dari sisi job.
                Tables\Filters\SelectFilter::make('status')
                    ->label('Queue Status')
                    ->options([
                        'pending'   => 'Pending',
                        'processing'=> 'Processing',
                        'sent'      => 'Sent',
                        'failed'    => 'Failed',
                    ]),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exports([
                        ExcelExport::make('table')
                            ->fromTable()
                            ->only([
                                'campaign.name',
                                'recipient.name',
                                'recipient.phone',
                                'merged_status',
                                'error_message',
                                'sent_at',
                            ])
                            ->modifyQueryUsing(function (Builder $query) {
                                return $query->with([
                                    'campaign', 'recipient', 'latestWebhookByWamid', 'latestWebhook',
                                ]);
                            }),
                    ]),

                Tables\Actions\Action::make('sendBroadcast')
                    ->label('Send Pending Broadcasts')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function () {
                        \Artisan::call('broadcast:send');
                        $output = \Artisan::output();

                        \Filament\Notifications\Notification::make()
                            ->title('Broadcast Executed')
                            ->body("Command executed:\n\n{$output}")
                            ->success()
                            ->send();
                    })
                    ->disabled(fn () => ! BroadcastMessage::where('status', 'pending')->exists()),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBroadcasts::route('/'),
        ];
    }
}
