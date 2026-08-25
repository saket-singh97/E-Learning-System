<?php

namespace App\Domains\Assessments\Filament\Resources\OmrSheetScans;

use App\Domains\Assessments\Filament\Resources\OmrSheetScans\Pages\CreateOmrSheetScan;
use App\Domains\Assessments\Filament\Resources\OmrSheetScans\Pages\ListOmrSheetScans;
use App\Domains\Assessments\Models\OmrSheetScan;
use App\Domains\Assessments\Models\OmrTemplate;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S16 B15 (I11 pt.2) — upload-then-status-list shape, same as
 * `StudentImportBatchResource` (S15 B9/B10): Phase 1 (this page) stores
 * the image and creates a `pending` row; Phase 2 (`ProcessOmrSheetScan`,
 * always dispatched) does the actual scan/resolve/grade and lands the
 * final status. Admin panel, registered via an explicit `->resources()`
 * entry (the Assessments resource directory is otherwise Teach-panel-only).
 */
class OmrSheetScanResource extends Resource
{
    protected static ?string $model = OmrSheetScan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static ?string $navigationLabel = 'OMR Scans';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('omr_template_id')
                ->label('OMR template')
                ->options(fn (): array => OmrTemplate::with('quiz')->get()->mapWithKeys(
                    fn (OmrTemplate $template): array => [$template->id => $template->quiz?->title ?? "Template #{$template->id}"]
                )->all())
                ->required(),
            FileUpload::make('image')
                ->label('Scanned bubble sheet')
                ->disk('local')
                ->directory('omr-scans')
                ->acceptedFileTypes(['image/png', 'image/jpeg'])
                ->required()
                ->dehydrated(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('template.quiz.title')->label('Quiz'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'processed' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('detected_roll_number')->label('Detected roll #')->placeholder('—'),
                TextColumn::make('matchedUser.name')->label('Matched student')->placeholder('—'),
                TextColumn::make('uploadedBy.name')->label('Uploaded by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOmrSheetScans::route('/'),
            'create' => CreateOmrSheetScan::route('/create'),
        ];
    }
}
