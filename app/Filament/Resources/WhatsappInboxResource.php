<?php

namespace App\Filament\Resources;

use App\Models\WhatsappWebhook;
use App\Models\Recipient;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Query\Builder as Qb;
use Illuminate\Support\Facades\DB;

class WhatsappInboxResource extends Resource
{
    protected static ?string $model = WhatsappWebhook::class;
    protected static ?string $navigationGroup = 'WhatsApp Service';
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Inbox';

    public static function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $biz = config('services.whatsapp.business_number');

                // Subquery: ambil last_id per contact
                $threads = DB::table('whatsapp_webhooks as w')
                    ->selectRaw(
                        "CASE WHEN w.from_number = ? THEN w.to_number ELSE w.from_number END AS contact_phone",
                        [$biz]
                    )
                    ->selectRaw("MAX(w.id) AS last_id")
                    ->where('w.event_type', 'message')
                    ->groupBy('contact_phone');

                // Kembalikan Eloquent\Builder dan pastikan ID ikut terpilih
                return WhatsappWebhook::query()
                    ->fromSub($threads, 't')
                    ->join('whatsapp_webhooks as m', 'm.id', '=', 't.last_id')
                    ->leftJoin('recipients as r', 'r.phone', '=', 't.contact_phone')
                    ->select([
                        DB::raw('m.*'), // ⬅️ ini memastikan ada kolom id untuk baris
                        DB::raw('t.contact_phone as contact_phone'),
                        DB::raw('m.timestamp as last_at'),
                        DB::raw('COALESCE(r.name, t.contact_phone) as display_name'),
                    ])
                    ->orderByDesc('last_at');
            })
            ->columns([
                TextColumn::make('display_name')
                    ->label('Contact')
                    ->searchable(),

                TextColumn::make('last_message')
                    ->label('Last message')
                    ->getStateUsing(function ($record) {
                        $p = $record->payload;
                        $a = is_array($p)
                            ? $p
                            : (json_decode($p, true)
                                ?: json_decode(stripslashes((string) $p), true)
                                ?: []);
                        return $a['text']['body']
                            ?? ($a['image']['caption']
                                ?? ($a['document']['caption'] ?? '—'));
                    })
                    ->limit(60),

                TextColumn::make('last_at')
                    ->label('When')
                    ->dateTime('d M Y H:i:s'),
            ])
            ->actions([
                Tables\Actions\Action::make('Open')
                    ->icon('heroicon-o-paper-airplane')
                    ->url(fn ($record) => static::getUrl('chat', ['phone' => $record->contact_phone])),
            ])
            ->filters([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => WhatsappInboxResource\Pages\ListThreads::route('/'),
            'chat'  => WhatsappInboxResource\Pages\ViewConversation::route('/chat/{phone}'),
        ];
    }
}
