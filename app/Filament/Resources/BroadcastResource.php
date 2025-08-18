<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BroadcastResource\Pages;
use App\Filament\Resources\BroadcastResource\RelationManagers;
use App\Models\Broadcast;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Models\WhatsAppTemplate;
use App\Models\Recipient;
use App\Models\BroadcastMessage;
use App\Models\Campaign;
use Filament\Tables\Actions\Action;
use App\Models\WhatsappWebhook;

class BroadcastResource extends Resource
{
    protected static ?string $model = BroadcastMessage::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'WhatsApp Service';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('Campaign Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('template.name')
                    ->label('Template')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('recipient.name')
                    ->label('Recipients'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status'),
                Tables\Columns\TextColumn::make('sent_at')
                    ->dateTime()
                    ->label('Sent At')
                    ->sortable(),
            ])
            ->actions([
                Action::make('seeLog')
                    ->label('See Log')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->modalHeading('Broadcast Webhook Logs')
                    ->modalButton('Close')
                    ->modalContent(function ($record) {
                        $logs = WhatsappWebhook::where('broadcast_id', $record->id)
                            ->orderByDesc('created_at')
                            ->get()
                            ->map(function ($log) {
                                $payload = json_decode($log->payload, true);
                                return [
                                    'status' => $payload['status'] ?? '-',
                                    'timestamp' => isset($payload['timestamp'])
                                        ? date('Y-m-d H:i:s', $payload['timestamp'])
                                        : null,
                                    'recipient_id' => $payload['recipient_id'] ?? '-',
                                    'raw' => json_encode($payload, JSON_PRETTY_PRINT),
                                ];
                            });
            
                        return view('filament.custom.broadcast-logs', [
                            'logs' => $logs,
                        ]);
                    })
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('campaign_id')
                    ->label('Campaign')
                    ->options(
                        Campaign::query()
                            ->distinct()
                            ->orderBy('name')
                            ->pluck('name', 'id') // key = id, value = name
                            ->filter()
                    ),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                        'delivered' => 'Delivered',
                        'read' => 'Read'
                    ])
            ])
            ->headerActions([
                Tables\Actions\Action::make('sendBroadcast')
                    ->label('Send Pending Broadcasts')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function () {
                        // Jalankan artisan command
                        \Artisan::call('broadcast:send');

                        // Ambil output command untuk ditampilkan
                        $output = \Artisan::output();

                        // Tambahkan notifikasi sukses
                        \Filament\Notifications\Notification::make()
                            ->title('Broadcast Executed')
                            ->body("Command executed:\n\n{$output}")
                            ->success()
                            ->send();
                    })
                    ->disabled(function () {
                        // Disable kalau tidak ada pending
                        return ! \App\Models\BroadcastMessage::where('status', 'pending')->exists();
                    })
                ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBroadcasts::route('/')
        ];
    }
}
