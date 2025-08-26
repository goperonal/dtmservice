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
        $biz = (string) config('services.whatsapp.business_phone');

        // Subquery: daftar thread per kontak (hanya pesan yang melibatkan nomor bisnis)
        $threads = DB::table('whatsapp_webhooks as w')
            ->where('w.event_type', 'message')
            ->where(function ($q) use ($biz) {
                $q->where('w.from_number', $biz)
                ->orWhere('w.to_number', $biz);
            })
            ->selectRaw(
                "CASE WHEN w.from_number = ? THEN w.to_number ELSE w.from_number END AS contact_phone",
                [$biz]
            )
            ->selectRaw("MAX(w.timestamp) AS last_at")
            ->selectRaw("MAX(w.id) AS last_id")
            ->groupBy('contact_phone')
            ->having('contact_phone', '<>', $biz);

        // Eloquent builder: joinSub($threads) ke tabel whatsapp_webhooks
        $eloquent = WhatsappWebhook::query()
            ->joinSub($threads, 't', function ($join) {
                // baris whatsapp_webhooks = pesan terakhir (last_id)
                $join->on('whatsapp_webhooks.id', '=', 't.last_id');
            })
            ->leftJoin('recipients as r', 'r.phone', '=', 't.contact_phone')
            ->select([
                'whatsapp_webhooks.*',               // semua kolom, termasuk id
                't.contact_phone',
                't.last_at',
                DB::raw('COALESCE(r.name, t.contact_phone) as display_name'),
            ])
            ->orderByDesc('t.last_at');

        return $table
            // ⬇️ Pastikan kirim Eloquent\Builder (pakai Closure)
            ->query(fn () => $eloquent)
            ->columns([
                TextColumn::make('display_name')
                    ->label('Contact')
                    ->searchable(),

                TextColumn::make('last_message')
                    ->label('Last message')
                    ->getStateUsing(function ($record) {
                        // $record adalah instance WhatsappWebhook dengan kolom extra
                        $p = $record->payload;
                        $a = is_array($p)
                            ? $p
                            : (json_decode($p, true) ?: json_decode(stripslashes((string) $p), true) ?: []);

                        return $a['text']['body']
                            ?? $a['image']['caption']
                            ?? $a['document']['caption']
                            ?? '—';
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
