<?php

namespace App\Helpers;

use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ExcelExportHelper
{
    /**
     * Generate and stream a standard Microsoft Excel (.xlsx) file with full UTF-8 Unicode support.
     *
     * @param  array<string>  $headers  List of column header titles
     * @param  iterable<array<mixed>>  $rows  List of rows, each row being an indexed array of cell values
     * @param  string  $filename  The downloaded file name (e.g. Ho_so_dang_ky_creator.xlsx)
     */
    public static function streamXlsx(array $headers, iterable $rows, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($headers, $rows) {
            $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');

            $zip = new ZipArchive();
            if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create temporary Excel archive.');
            }

            // 1. [Content_Types].xml
            $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>';
            $zip->addFromString('[Content_Types].xml', $contentTypes);

            // 2. _rels/.rels
            $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>';
            $zip->addFromString('_rels/.rels', $rootRels);

            // 3. xl/_rels/workbook.xml.rels
            $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>';
            $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);

            // 4. xl/workbook.xml
            $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets>'
                .'<sheet name="Sheet1" sheetId="1" r:id="rId1"/>'
                .'</sheets>'
                .'</workbook>';
            $zip->addFromString('xl/workbook.xml', $workbookXml);

            // 5. xl/styles.xml (Simple default style with bold header font)
            $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="2">'
                .'<font><sz val="11"/><name val="Calibri"/></font>'
                .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
                .'</fonts>'
                .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="2">'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                .'</cellXfs>'
                .'</styleSheet>';
            $zip->addFromString('xl/styles.xml', $stylesXml);

            // 6. xl/worksheets/sheet1.xml
            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetData>';

            $rowIdx = 1;

            // Write Header Row (style 1 = bold)
            $sheetXml .= '<row r="'.$rowIdx.'">';
            foreach ($headers as $colIdx => $title) {
                $cellRef = self::colName($colIdx).$rowIdx;
                $val = htmlspecialchars((string) $title, ENT_XML1, 'UTF-8');
                $sheetXml .= '<c r="'.$cellRef.'" t="inlineStr" s="1"><is><t>'.$val.'</t></is></c>';
            }
            $sheetXml .= '</row>';

            // Write Data Rows
            foreach ($rows as $row) {
                $rowIdx++;
                $sheetXml .= '<row r="'.$rowIdx.'">';
                foreach (array_values($row) as $colIdx => $cellValue) {
                    $cellRef = self::colName($colIdx).$rowIdx;
                    $val = htmlspecialchars((string) ($cellValue ?? ''), ENT_XML1, 'UTF-8');
                    $sheetXml .= '<c r="'.$cellRef.'" t="inlineStr"><is><t>'.$val.'</t></is></c>';
                }
                $sheetXml .= '</row>';
            }

            $sheetXml .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

            $zip->close();

            readfile($tempFile);
            @unlink($tempFile);
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Convert zero-based column index to Excel column name (A, B, ... Z, AA, AB...).
     */
    private static function colName(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = (int) (($index - $mod) / 26);
        }

        return $letters;
    }
}
