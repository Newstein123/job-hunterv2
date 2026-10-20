<?php

namespace App\Services;

use ZipArchive;

/**
 * Plain-text view of a .docx (paragraphs and table cells), for previewing in the browser.
 * Formatting is dropped; the AI service does its own extraction for matching.
 */
class DocxText
{
    public static function fromBytes(string $bytes): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive;
            if ($zip->open($tmp) !== true) {
                return null;
            }
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        if ($xml === false) {
            return null;
        }

        $xml = str_replace(['</w:p>', '<w:br/>', '<w:cr/>'], "\n", $xml);
        $xml = str_replace(['<w:tab/>', '</w:tc>'], "\t", $xml);
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
