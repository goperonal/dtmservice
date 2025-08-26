<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WhatsappInboxResource\Pages\ListWhatsappInbox;
use App\Filament\Resources\WhatsappInboxResource\Pages\ViewConversation;
use App\Models\WhatsappWebhook;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;

class WhatsappInboxResource extends Resource
{
    protected static ?string $model = WhatsappWebhook::class;

    protected static ?string $navigationIcon  = 'heroicon-o-inbox';
    protected static ?string $navigationGroup = 'WhatsApp Service';
    protected static ?string $navigationLabel = 'Inbox';
    protected static ?int    $navigationSort  = 5;

    /**
     * Thread per kontak:
     * - Tentukan contact_phone = CASE WHEN from_number = business_phone THEN to_number ELSE from_number
     * - Ambil MAX(id) per contact_phone (row terakhir)
     * - Join recipients untuk ambil name kalau ada
     */
    public static function getEloquentQuery(): Builder
    {
        $bp   = (string) config('services.whatsapp.business_phone', '');
        $tbl  = (new WhatsappWebhook)->getTable();

        // subquery id terakhir per contact_phone, hanya event pesan & exclude broadcast
        $latestPerContact = WhatsappWebhook::query()
            ->where('event_type', 'message')
            ->when(Schema::hasColumn($tbl, 'broadcast_id'), fn ($q) => $q->whereNull('broadcast_id'))
            ->selectRaw('MAX(id) AS id')
            ->selectRaw("CASE WHEN {$tbl}.`from_number` = ? THEN {$tbl}.`to_number` ELSE {$tbl}.`from_number` END AS contact_phone", [$bp])
            ->groupBy('contact_phone');

        // join dengan table asli + recipients
        return WhatsappWebhook::query()
            ->joinSub($latestPerContact, 't', function ($join) use ($tbl) {
                $join->on("{$tbl}.id", '=', 't.id');
            })
            ->leftJoin('recipients', 'recipients.phone', '=', 't.contact_phone')
            ->select("{$tbl}.*")
            ->selectRaw('t.contact_phone')
            ->selectRaw('recipients.name AS recipient_name')
            ->orderByDesc("{$tbl}.id");
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                TextColumn::make('contact')
                    ->label('Contact')
                    ->getStateUsing(fn (WhatsappWebhook $record) => $record->recipient_name ?: $record->contact_phone)
                    ->searchable(),

                TextColumn::make('preview')
                    ->label('Last Message')
                    ->limit(80)
                    ->wrap()
                    ->getStateUsing(function (WhatsappWebhook $record) {
                        $p = is_array($record->payload) ? $record->payload : json_decode((string) $record->payload, true);
                        if (! is_array($p)) return '-';

                        $type = $p['type'] ?? null;
                        if ($type === 'text') {
                            return data_get($p, 'text.body') ?: 'text';
                        }

                        $cap = data_get($p, "{$type}.caption");
                        if ($cap) return $cap;

                        return strtoupper((string) ($type ?: '-'));
                    }),

                TextColumn::make('received_at_wib')
                    ->label('Time (WIB)')
                    ->getStateUsing(fn (WhatsappWebhook $record) =>
                        optional($record->timestamp ?? $record->created_at)?->timezone('Asia/Jakarta')?->format('d M Y H:i')
                    )
                    ->sortable(query: fn (Builder $q, string $dir) => $q->orderBy('timestamp', $dir)),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (WhatsappWebhook $record) => static::getUrl('chat', [
                'phone' => $record->contact_phone,
            ]))
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn (WhatsappWebhook $record) => static::getUrl('chat', [
                        'phone' => $record->contact_phone,
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsappInbox::route('/'),
            'chat'  => ViewConversation::route('/chat/{phone?}'), // param opsional biar aman
        ];
    }
}
