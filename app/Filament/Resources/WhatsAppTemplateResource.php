<?php

namespace App\Filament\Resources;

use App\Models\WhatsAppTemplate;
use Filament\Forms\Form;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Actions\Action;
use App\Filament\Resources\WhatsAppTemplateResource\Pages;
use App\Services\WhatsAppTemplateService;
use Illuminate\Support\Facades\Log;

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
                        ->afterStateUpdated(fn($state, $set) => $set('name', str_replace(' ', '_', strtolower($state)))),

                    Hidden::make('languages')->default('id')->required(),
                    Hidden::make('parameter_format')->default('POSITIONAL')->required(),
                    Hidden::make('status')->default('PENDING')->required(),

                    Select::make('category')
                        ->label('Category')
                        ->required()
                        ->options([
                            'MARKETING' => 'MARKETING',
                            'UTILITY' => 'UTILITY',
                        ]),
                ]),

            Grid::make()->columns(['default' => 10])
                ->schema([
                    Fieldset::make('Content')
                        ->schema([
                            Wizard::make([
                                Step::make('Header')->schema([
                                    Select::make('header.type')
                                        ->label('Header Type')
                                        ->options([
                                            'none' => 'None',
                                            'text' => 'Text',
                                            'image' => 'Image',
                                        ])
                                        ->default('none')
                                        ->reactive(),

                                    TextInput::make('header.text')
                                        ->label('Header Text')
                                        ->placeholder('Masukkan teks header')
                                        ->visible(fn($get) => $get('header.type') === 'text')
                                        ->required(fn($get) => $get('header.type') === 'text'),

                                    FileUpload::make('header.media_url')
                                        ->label('Header Image')
                                        ->directory('whatsapp-templates/headers')
                                        ->image()
                                        ->visible(fn ($get) => $get('header.type') === 'image')
                                        ->required(fn ($get) => $get('header.type') === 'image')
                                ]),
                                Step::make('Body')->schema([
                                    Textarea::make('body.text')
                                        ->label('Body Text')
                                        ->required()
                                        ->placeholder('Contoh: Hai {{1}}, pesanan Anda {{2}} sedang dikirim.'),
                                    TextInput::make('body.example')
                                        ->helperText('Comma separated example values')
                                ]),
                                Step::make('Footer')->schema([
                                    TextInput::make('footer.text')
                                        ->label('Footer Text')
                                        ->placeholder('Contoh: Terima kasih telah berbelanja.'),
                                ]),
                                Step::make('Buttons')->schema([
                                    Repeater::make('buttons')
                                        ->label('Action Buttons')
                                        ->schema([
                                            Select::make('type')
                                                ->label('Button Type')
                                                ->options(['url' => 'URL'])
                                                ->required(),
                                            TextInput::make('text')->label('Button Text')->required(),
                                            TextInput::make('url')->label('URL')->url()->required(),
                                        ])
                                        ->maxItems(1)
                                        ->default([]),
                                ]),
                            ]),
                        ])->columns(1)->columnSpan(7),

                    Fieldset::make('Preview')->columns(1)->columnSpan(3),
                ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('category'),
                BadgeColumn::make('status')
                    ->colors([
                        'success' => 'APPROVED',
                        'warning' => 'PENDING',
                        'danger'  => 'REJECTED',
                    ]),
                TextColumn::make('languages'),
            ])
            ->actions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'FAILED')
                    ->requiresConfirmation()
                    ->action(fn ($record) => app(WhatsAppTemplateService::class)->pushTemplate($record)),

                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Preview Template')
                    ->modalContent(fn ($record) => view('preview-template', ['components' => $record->components]))
                    ->button(),
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
            'index' => Pages\ListWhatsAppTemplates::route('/'),
            'create' => Pages\CreateWhatsAppTemplate::route('/create'),
        ];
    }
}
