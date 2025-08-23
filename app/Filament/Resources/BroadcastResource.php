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
use pxlrbt\FilamentExcel\Exports\ExcelExport; // ✅ benar

class BroadcastResource extends Resource
{
    protected static ?string $model = BroadcastMessage::class;

    protected static ?string $navigationIcon  = 'heroicon-o-list-bullet';
    protected static ?string $navigationGroup = 'WhatsApp Service';
    protected static ?int    $navigationSort  = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    // ✅ eager load agar kolom accessor tidak N+1
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['campaign', 'recipient', 'latestWebhook']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('recipient.name')
                    ->label('Recipients'),

                Tables\Columns\TextColumn::make('recipient.phone')
                    ->label('Phone')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status'),

                // ✅ gunakan accessor 'error_message' dari model (ikut export)
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Pesan Error')
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('Campaign Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->dateTime()
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
                        $logs = \App\Models\WhatsappWebhook::where('broadcast_id', $record->id)
                            ->orderByDesc('created_at')
                            ->get()
                            ->map(function ($log) {
                                // Normalisasi payload: terima array atau string JSON
                                $payload = $log->payload;
                                if (is_string($payload)) {
                                    $decoded = json_decode($payload, true);
                                    $payload = is_array($decoded) ? $decoded : [];
                                } elseif (! is_array($payload)) {
                                    $payload = [];
                                }

                                $ts = data_get($payload, 'timestamp');
                                $timestamp = is_numeric($ts) ? date('Y-m-d H:i:s', (int) $ts) : null;

                                return [
                                    'status'       => data_get($payload, 'status', '-'),
                                    'timestamp'    => $timestamp,
                                    'recipient_id' => data_get($payload, 'recipient_id', '-'),
                                    // (opsional) ringkas pesan error
                                    'message'      => data_get($payload, 'errors.0.message')
                                                    ?? data_get($payload, 'errors.0.error_data.details')
                                                    ?? data_get($payload, 'message')
                                                    ?? null,
                                    'raw'          => json_encode($payload, JSON_PRETTY_PRINT),
                                ];
                            });

                        return view('filament.custom.broadcast-logs', compact('logs'));
                    }),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
                ExportBulkAction::make()
                    ->exports([
                        ExcelExport::make('selected')
                            ->fromTable()
                            ->only([
                                'campaign.name',
                                'recipient.name',
                                'recipient.phone',
                                'status',
                                'error_message',   // ✅ ikut export
                                'sent_at',
                            ])
                            // ✅ ketik-hint Builder biar tidak error DI
                            ->modifyQueryUsing(function (\Illuminate\Database\Eloquent\Builder $query) {
                                return $query->with(['campaign', 'recipient', 'latestWebhook']);
                            }),
                    ])
                    ->icon('heroicon-o-document-arrow-up'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('campaign_id')
                    ->label('Campaign')
                    ->options(
                        Campaign::query()
                            ->distinct()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->filter()
                    ),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Pending',
                        'sent'      => 'Sent',
                        'failed'    => 'Failed',
                        'delivered' => 'Delivered',
                        'read'      => 'Read',
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
                                'status',
                                'error_message',   // ✅ ikut export
                                'sent_at',
                            ])
                            ->modifyQueryUsing(function (\Illuminate\Database\Eloquent\Builder $query) {
                                return $query->with(['campaign', 'recipient', 'latestWebhook']);
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
