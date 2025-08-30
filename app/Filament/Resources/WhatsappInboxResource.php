<?php

namespace App\Filament\Resources;

use App\Models\WhatsappWebhook;
use Filament\Resources\Resource;
use App\Filament\Resources\WhatsappInboxResource\Pages\Workspace;

class WhatsappInboxResource extends Resource
{
    protected static ?string $model = WhatsappWebhook::class;
    protected static ?string $navigationGroup = 'Inboxes';
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'WhatsApp Inbox';

    /** Badge unread di sidebar */
    public static function getNavigationBadge(): ?string
    {
        $biz = (string) config('services.whatsapp.business_phone');

        try {
            $count = WhatsappWebhook::query()
                ->where('event_type', 'message')
                ->where('to_number', $biz)          // masuk ke bisnis
                ->where('from_number', '!=', $biz)  // dari kontak
                ->whereNull('read_at')
                ->count();

            return $count ? (string) $count : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Workspace::route('/'),
        ];
    }
}
