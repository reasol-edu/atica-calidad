<?php

declare(strict_types=1);

namespace App\Search\Extractor;

/**
 * Reads XML parts out of a ZIP package (Office Open XML and OpenDocument files) with a hard ceiling on how much of each part is
 * ever inflated, whatever size the archive claims for it: the size in a ZIP directory is the file's own word, and a "zip bomb"
 * is a small file that inflates to gigabytes. XML is parsed without loading anything from the network or the disk and without
 * substituting entities (libxml's own entity-expansion guard stays on: never LIBXML_NOENT or LIBXML_PARSEHUGE).
 */
final class ZipXml
{
    /** Most bytes of one XML part that are read; a part bigger than that is not an office document, it is an attack or a dump. */
    public const int MAX_PART_BYTES = 25 * 1024 * 1024;

    /** Most entries a package may list before it is refused. */
    public const int MAX_ENTRIES = 5_000;

    public static function open(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new ExtractionException('El fichero no es un paquete ZIP válido.');
        }
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new ExtractionException('El paquete contiene demasiadas partes.');
        }

        return $zip;
    }

    /** @return list<string> names of the package's parts that match $pattern (a PCRE), in natural order */
    public static function names(\ZipArchive $zip, string $pattern): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (\is_string($name) && preg_match($pattern, $name) === 1) {
                $names[] = $name;
            }
        }
        natsort($names);

        return array_values($names);
    }

    /** The part's bytes, or null when the package has no such part. @throws ExtractionException when it is bigger than the ceiling */
    public static function read(\ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new ExtractionException('Una parte del documento es demasiado grande.');
        }

        $stream = $zip->getStream($name);
        if ($stream === false) {
            return null;
        }

        $data = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
            if (\strlen($data) > self::MAX_PART_BYTES) {
                fclose($stream);
                throw new ExtractionException('Una parte del documento es demasiado grande.');
            }
        }
        fclose($stream);

        return $data;
    }

    /**
     * The text of an XML part: the text of every element named in $textElements (by local name, namespace ignored) when that
     * list is given, otherwise of every text node, with a space where an element in $spaceElements opens or closes so
     * neighbouring words do not run together. Stops after $maxChars.
     *
     * @param list<string>|null $textElements
     * @param list<string>      $spaceElements
     */
    public static function text(string $xml, ?array $textElements, array $spaceElements, int $maxChars): string
    {
        $previous = libxml_use_internal_errors(true);
        $reader   = new \XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            libxml_use_internal_errors($previous);
            throw new ExtractionException('El documento contiene XML mal formado.');
        }

        $text  = '';
        $texts = $textElements === null ? null : array_flip($textElements);
        $space = array_flip($spaceElements);

        try {
            while ($reader->read()) {
                $type = $reader->nodeType;
                if ($type === \XMLReader::ELEMENT) {
                    $name = $reader->localName;
                    if (isset($space[$name])) {
                        $text .= ' ';
                    }
                    if ($texts !== null && isset($texts[$name]) && !$reader->isEmptyElement) {
                        $text .= $reader->readString();
                    }
                } elseif ($type === \XMLReader::END_ELEMENT) {
                    if (isset($space[$reader->localName])) {
                        $text .= ' ';
                    }
                } elseif ($texts === null && ($type === \XMLReader::TEXT || $type === \XMLReader::CDATA || $type === \XMLReader::SIGNIFICANT_WHITESPACE)) {
                    $text .= $reader->value;
                }
                // Bytes, not characters (counting those on every node would be quadratic): a little over-read does no harm, the indexer trims.
                if (\strlen($text) >= $maxChars * 2) {
                    break;
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $text;
    }
}
