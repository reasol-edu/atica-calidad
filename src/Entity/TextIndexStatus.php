<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * What came out of reading a stored file for the text index. A file with no row at all has not been looked at yet.
 */
enum TextIndexStatus: string
{
    /** Its text is in the index and can be searched. */
    case Indexed = 'indexed';

    /** Read fine but there is no text in it: a scan, a photo, a drawing. Findable by name only. */
    case NoText = 'no_text';

    /** A format nothing here can read (an old .doc, an image, a ZIP…). */
    case Unsupported = 'unsupported';

    /** Bigger than the limit for reading a file of that kind. */
    case TooLarge = 'too_large';

    /** The file is damaged, encrypted or otherwise unreadable. */
    case Failed = 'failed';

    /**
     * A scan being read with OCR a few pages at a time (see DocumentOcrService): what has been read so far is already searchable,
     * and the row turns into Indexed or NoText when the last page is done.
     */
    case OcrPending = 'ocr_pending';

    /** Translation key (admin domain) of the label shown in the system status. */
    public function labelKey(): string
    {
        return 'system.search_index.status.' . $this->value;
    }
}
