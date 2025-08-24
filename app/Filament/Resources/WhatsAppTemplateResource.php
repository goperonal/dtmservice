<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WhatsAppTemplateResource\Pages;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppTemplateService;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\View;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Filament\Tables\Actions\ViewAction;
use Filament\Infolists\Components\View as InfoView;
use Filament\Support\Enums\Alignment;

class WhatsAppTemplateResource extends Resource
{
    protected static ?string $model = WhatsAppTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'WhatsApp Service';
    protected static ?string $navigationLabel = 'Templates';
    protected static ?string $modelLabel = 'WhatsApp Template';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Fieldset::make('Informasi Template')
                ->schema([
                    TextInput::make('name')
                        ->label('Template Name')
                        ->required()
                        ->rules(['regex:/^[a-z0-9_]+$/'])
                        ->helperText('Gunakan huruf kecil tanpa spasi, misalnya: promo_juli_2025')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, $set) => $set('name', str_replace(' ', '_', strtolower((string) $state)))),
                    Select::make('category')
                        ->label('Category')
                        ->required()
                        ->default('MARKETING')
                        ->options([
                            'MARKETING' => 'MARKETING',
                            'UTILITY'   => 'UTILITY',
                            // kalau perlu tambah:
                            // 'AUTHENTICATION' => 'AUTHENTICATION',
                        ]),

                    Hidden::make('languages')->default('id')->required(),
                    Hidden::make('parameter_format')->default('POSITIONAL')->required(),
                    Hidden::make('status')->default('PENDING')->required(),
                ]),

            Grid::make()->columns([
                'sm' => 1,
                'lg' => 10,
            ])->schema([
                Fieldset::make('Content')
                    ->schema([
                        Wizard::make([
                            Step::make('Header')->schema([
                                Select::make('header.type')
                                    ->label('Header Type')
                                    ->options([
                                        'none'  => 'None',
                                        'text'  => 'Text',
                                        'image' => 'Image',
                                    ])
                                    ->default('none')
                                    ->live(),

                                TextInput::make('header.text')
                                    ->label('Header Text')
                                    ->placeholder('Masukkan teks header')
                                    ->visible(fn (Get $get) => $get('header.type') === 'text')
                                    ->required(fn (Get $get) => $get('header.type') === 'text')
                                    ->live(debounce: 300),

                                // URL sementara untuk preview (tidak disimpan ke DB)
                                Hidden::make('header.media_preview_url')
                                    ->dehydrated(false),

                                FileUpload::make('header.media_url')
                                    ->label('Header Image')
                                    ->disk('public')
                                    ->directory('whatsapp-templates/headers')
                                    ->image()
                                    ->multiple(false)
                                    ->live()
                                    // simpan file saat submit; JANGAN ubah state manual
                                    ->saveUploadedFileUsing(function (TemporaryUploadedFile $file) {
                                        return $file->store('whatsapp-templates/headers', 'public');
                                    })
                                    ->visible(fn (Get $get) => $get('header.type') === 'image')
                                    ->required(fn (Get $get) => $get('header.type') === 'image')
                                    ->afterStateUpdated(function (mixed $state, Set $set) {
                                        // saat upload SELESAI, Filament akan mengirim TemporaryUploadedFile
                                        if ($state instanceof TemporaryUploadedFile) {
                                            $set('header.media_preview_url', $state->temporaryUrl());
                                            return;
                                        }

                                        // beberapa saat state bisa bentuk array (UUID map) → tunggu next tick
                                        if (is_array($state)) {
                                            // biarkan; nanti akan dipanggil lagi saat jadi TemporaryUploadedFile
                                            return;
                                        }

                                        // kalau state string (edit mode / sudah tersimpan)
                                        if (is_string($state)) {
                                            $url = (str_starts_with($state, 'http://') || str_starts_with($state, 'https://') || str_starts_with($state, '/storage/'))
                                                ? $state
                                                : Storage::disk('public')->url($state);
                                            $set('header.media_preview_url', $url);
                                        }
                                    }),
                            ]),

                            Step::make('Body')->schema([
                                Textarea::make('body.text')
                                    ->label('Body Text')
                                    ->required()
                                    ->placeholder('Contoh: Hai {{1}}, pesanan Anda {{2}} sedang dikirim.')
                                    ->live(debounce: 300),

                                TextInput::make('body.example')
                                    ->helperText('Comma separated example values')
                                    ->live(debounce: 300),
                            ]),

                            Step::make('Footer')->schema([
                                TextInput::make('footer.text')
                                    ->label('Footer Text')
                                    ->placeholder('Contoh: Terima kasih telah berbelanja.')
                                    ->live(debounce: 300),
                            ]),

                            Step::make('Buttons')->schema([
                                Repeater::make('buttons')
                                    ->label('Action Buttons')
                                    ->schema([
                                        Select::make('type')
                                            ->label('Button Type')
                                            ->options(['url' => 'URL'])
                                            ->required()
                                            ->live(),
                                        TextInput::make('text')
                                            ->label('Button Text')
                                            ->required()
                                            ->live(debounce: 300),
                                        TextInput::make('url')
                                            ->label('URL')
                                            ->url()
                                            ->required()
                                            ->live(debounce: 300),
                                    ])
                                    ->maxItems(1)
                                    ->default([])
                                    ->live(),
                            ]),
                        ])
                            ->contained(false)
                            ->extraAttributes(['class' => 'w-full max-w-none']),
                    ])
                    ->columns(1)
                    ->columnSpan(['sm' => 1, 'lg' => 6]),

                Fieldset::make('Preview')
                    ->columns(1)
                    ->columnSpan(['sm' => 1, 'lg' => 4])
                    ->extraAttributes(['class' => 'lg:sticky lg:top-4'])
                    ->schema([
                        View::make('filament.whatsapp-template-preview')
                            ->reactive()
                            ->viewData(function (Get $get) {
                                // 1) pakai URL preview dulu (temporary)
                                $headerImageUrl = $get('header.media_preview_url');

                                // 2) kalau belum ada, fallback dari media_url tersimpan
                                if (empty($headerImageUrl)) {
                                    $media = $get('header.media_url');

                                    if ($media instanceof TemporaryUploadedFile) {
                                        $headerImageUrl = $media->temporaryUrl();
                                    } elseif (is_string($media)) {
                                        $headerImageUrl = (str_starts_with($media, 'http://')
                                            || str_starts_with($media, 'https://')
                                            || str_starts_with($media, '/storage/'))
                                            ? $media
                                            : Storage::disk('public')->url($media);
                                    } elseif (is_array($media)) {
                                        // coba ambil kandidat di array
                                        $candidate = data_get($media, 'url')
                                            ?? data_get($media, 'temporaryUrl')
                                            ?? data_get($media, 'path');

                                        if (! $candidate && ! empty($media)) {
                                            $first = reset($media);
                                            if ($first instanceof TemporaryUploadedFile) {
                                                $candidate = $first->temporaryUrl();
                                            } elseif (is_array($first)) {
                                                $candidate = data_get($first, 'url')
                                                    ?? data_get($first, 'temporaryUrl')
                                                    ?? data_get($first, 'path');
                                            }
                                        }

                                        if ($candidate) {
                                            $headerImageUrl = (str_starts_with($candidate, 'http')
                                                || str_starts_with($candidate, '/storage/'))
                                                ? $candidate
                                                : Storage::disk('public')->url($candidate);
                                        }
                                    }
                                }

                                // Normalisasi buttons → array dan kirim dgn nama unik (tplButtons)
                                $buttonsState = $get('buttons');
                                if (is_array($buttonsState)) {
                                    $tplButtons = $buttonsState;
                                } elseif (is_string($buttonsState)) {
                                    $decoded = json_decode($buttonsState, true);
                                    $tplButtons = is_array($decoded) ? $decoded : [];
                                } elseif ($buttonsState instanceof \Illuminate\Support\Collection) {
                                    $tplButtons = $buttonsState->toArray();
                                } else {
                                    $tplButtons = [];
                                }

                                return [
                                    'headerType'     => $get('header.type'),
                                    'headerText'     => $get('header.text'),
                                    'headerImageUrl' => $headerImageUrl,
                                    'bodyText'       => $get('body.text'),
                                    'footerText'     => $get('footer.text'),
                                    'tplButtons'     => $tplButtons,
                                    'phoneFrame'     => false,
                                ];
                            }),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('category'),
                BadgeColumn::make('status')->colors([
                    'success' => 'APPROVED',
                    'warning' => 'PENDING',
                    'danger'  => 'REJECTED',
                ]),
                TextColumn::make('languages'),
            ])
            ->actions([
                ViewAction::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(null)
                    ->modalWidth('md')
                    ->modalAlignment(Alignment::Start)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->infolist([
                        InfoView::make('whatsapp-template-preview')
                            ->view('filament.whatsapp-template-preview')
                            ->viewData(function ($record) {
                                $components = collect($record->components ?? []);

                                // HEADER
                                $headerComp  = $components->firstWhere('type', 'HEADER');
                                $headerType  = 'none';
                                $headerText  = null;
                                $headerImageUrl = null;

                                if ($headerComp) {
                                    $format = strtoupper($headerComp['format'] ?? '');
                                    if ($format === 'TEXT') {
                                        $headerType = 'text';
                                        $headerText = $headerComp['text'] ?? null;
                                    } elseif ($format === 'IMAGE') {
                                        $headerType = 'image';
                                        // kamu sudah menyimpan URL publik di kolom header_image_url saat create
                                        if (!empty($record->header_image_url)) {
                                            $headerImageUrl = $record->header_image_url;
                                        } else {
                                            // fallback kalau yang disimpan path relatif
                                            $path = $headerComp['media_url'] ?? null;
                                            if (is_string($path) && $path !== '') {
                                                $headerImageUrl = (
                                                    str_starts_with($path, 'http://') ||
                                                    str_starts_with($path, 'https://') ||
                                                    str_starts_with($path, '/storage/')
                                                ) ? $path : Storage::disk('public')->url($path);
                                            }
                                        }
                                    }
                                }

                                // BODY
                                $bodyComp  = $components->firstWhere('type', 'BODY');
                                $bodyText  = $bodyComp['text'] ?? null;

                                // FOOTER
                                $footerComp = $components->firstWhere('type', 'FOOTER');
                                $footerText = $footerComp['text'] ?? null;

                                // BUTTONS
                                $buttonsComp = $components->firstWhere('type', 'BUTTONS');
                                $tplButtons  = [];
                                if ($buttonsComp && is_array($buttonsComp['buttons'] ?? null)) {
                                    // Normalisasi ke bentuk yang dipakai blade
                                    $tplButtons = array_map(function ($b) {
                                        return [
                                            'type' => strtolower($b['type'] ?? 'url'),
                                            'text' => $b['text'] ?? 'Open',
                                            'url'  => $b['url']  ?? '#',
                                        ];
                                    }, $buttonsComp['buttons']);
                                }

                                return [
                                    'headerType'     => $headerType,
                                    'headerText'     => $headerText,
                                    'headerImageUrl' => $headerImageUrl,
                                    'bodyText'       => $bodyText,
                                    'footerText'     => $footerText,
                                    'tplButtons'     => $tplButtons,
                                    'phoneFrame'     => true,
                                ];
                            }),
                    ]),

                // 🔁 Retry (tetap ada)
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'FAILED')
                    ->requiresConfirmation()
                    ->action(fn ($record) => app(\App\Services\WhatsAppTemplateService::class)->pushTemplate($record)),
            ])
            ->headerActions([
                Action::make('Sync Now')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('success')
                    ->action(function () {
                        try {
                            $count = app(WhatsAppTemplateService::class)->sync();
                            \Filament\Notifications\Notification::make()
                                ->title("Synced $count templates")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Log::error($e);
                            \Filament\Notifications\Notification::make()
                                ->title('Sync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWhatsAppTemplates::route('/'),
            'create' => Pages\CreateWhatsAppTemplate::route('/create'),
        ];
    }
}
