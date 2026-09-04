<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The fixed, predetermined set of file formats a Folder can restrict uploads to (see
 * Folder::$allowedFormats / Folder::acceptsFile()). Not user-extensible — a closed set keeps the
 * restriction picker simple and each format's extensions/MIME types curatable in one place,
 * unlike the open-ended profile restriction lists elsewhere on Folder.
 */
enum AllowedFileFormat: string
{
    case EditableDocument    = 'editable_document';
    case NonEditableDocument = 'non_editable_document';
    case Presentation        = 'presentation';
    case Spreadsheet         = 'spreadsheet';
    case Image               = 'image';
    case Text                = 'text';

    /** Translation key (admin domain) for this format's label in a picker. */
    public function labelKey(): string
    {
        return 'folder.field.allowed_format.' . $this->value;
    }

    /** @return list<string> lowercase extensions (without the leading dot) this format accepts. */
    public function extensions(): array
    {
        return match ($this) {
            self::EditableDocument    => ['doc', 'docx', 'odt', 'rtf'],
            self::NonEditableDocument => ['pdf'],
            self::Presentation        => ['ppt', 'pptx', 'odp'],
            self::Spreadsheet         => ['xls', 'xlsx', 'ods', 'csv'],
            self::Image               => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tif', 'tiff'],
            self::Text                => ['txt', 'md', 'log'],
        };
    }

    /** @return list<string> lowercase MIME types this format accepts. */
    public function mimeTypes(): array
    {
        return match ($this) {
            self::EditableDocument => [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.oasis.opendocument.text',
                'application/rtf',
                'text/rtf',
            ],
            self::NonEditableDocument => ['application/pdf'],
            self::Presentation        => [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.presentation',
            ],
            self::Spreadsheet => [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.oasis.opendocument.spreadsheet',
                'text/csv',
            ],
            self::Image => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/bmp',
                'image/svg+xml',
                'image/tiff',
            ],
            self::Text => ['text/plain', 'text/markdown'],
        };
    }
}
