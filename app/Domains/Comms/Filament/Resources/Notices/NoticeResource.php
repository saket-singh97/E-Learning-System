<?php

namespace App\Domains\Comms\Filament\Resources\Notices;

use App\Domains\Comms\Filament\Resources\Notices\Pages\CreateNotice;
use App\Domains\Comms\Filament\Resources\Notices\Pages\EditNotice;
use App\Domains\Comms\Filament\Resources\Notices\Pages\ListNotices;
use App\Domains\Comms\Models\Notice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin-panel only (S6 pt.1, per this block's own plan text) — not
 * registered in TeachPanelProvider at all, same "not in Teach's
 * explicit/discovered list" precedent B3/B9 already established. B17
 * builds the portal/panel *display* side (who actually sees a published
 * notice), this block is authoring only.
 */
class NoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255),
            Textarea::make('body')
                ->required()
                ->maxLength(5000),
            Select::make('audience_roles')
                ->label('Audience')
                ->multiple()
                ->options(Notice::audienceRoleOptions())
                ->required(),
            DateTimePicker::make('published_at')
                ->native(false)
                ->helperText('Leave blank to save as a draft — publish it later from the list.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('audience_roles')
                    ->label('Audience')
                    ->formatStateUsing(function (array|string|null $state): string {
                        $roles = is_array($state) ? $state : (json_decode((string) $state, true) ?? []);

                        return implode(', ', $roles);
                    }),
                TextColumn::make('published_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Draft' : 'Published')
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'success'),
                TextColumn::make('creator.name')->label('Author'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('publishNow')
                    ->label('Publish now')
                    ->visible(fn (Notice $record): bool => ! $record->isPublished())
                    ->action(function (Notice $record): void {
                        $record->update(['published_at' => now()]);

                        Notification::make()->title('Notice published')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotices::route('/'),
            'create' => CreateNotice::route('/create'),
            'edit' => EditNotice::route('/{record}/edit'),
        ];
    }
}
