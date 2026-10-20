(function () {
    'use strict';

    const encoder = new TextEncoder();
    const workbookMimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    function xmlEscape(value) {
        return String(value)
            .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&apos;');
    }

    function columnName(index) {
        let name = '';
        while (index > 0) {
            index--;
            name = String.fromCharCode(65 + (index % 26)) + name;
            index = Math.floor(index / 26);
        }
        return name;
    }

    function inlineStringCell(reference, value, style) {
        return '<c r="' + reference + '" s="' + style + '" t="inlineStr"><is><t xml:space="preserve">' +
            xmlEscape(value) + '</t></is></c>';
    }

    function makeWorksheet(report) {
        const columnCount = report.headers.length;
        const finalColumn = columnName(columnCount);
        const lastDataRow = 6 + report.rows.length;
        const columns = report.columnWidths.map(function (width, index) {
            return '<col min="' + (index + 1) + '" max="' + (index + 1) + '" width="' +
                Math.max(10, Math.min(Number(width) || 18, 80)) + '" customWidth="1"/>';
        }).join('');
        const rows = [];

        rows.push('<row r="1" ht="30" customHeight="1">' +
            inlineStringCell('A1', report.title, 1) + '</row>');

        [
            ['REPORTING PERIOD', report.period],
            ['GENERATED', report.generatedAt],
            ['RECORDS INCLUDED', String(report.recordCount)]
        ].forEach(function (metadata, index) {
            const rowNumber = index + 2;
            rows.push('<row r="' + rowNumber + '" ht="22" customHeight="1">' +
                inlineStringCell('A' + rowNumber, metadata[0], 2) +
                inlineStringCell('B' + rowNumber, metadata[1], 3) + '</row>');
        });

        rows.push('<row r="5" ht="8" customHeight="1"/>');
        rows.push('<row r="6" ht="24" customHeight="1">' +
            report.headers.map(function (header, index) {
                return inlineStringCell(columnName(index + 1) + '6', header, 4);
            }).join('') + '</row>');

        report.rows.forEach(function (values, index) {
            const rowNumber = index + 7;
            const style = index % 2 === 0 ? 5 : 6;
            rows.push('<row r="' + rowNumber + '" ht="30" customHeight="1">' +
                values.map(function (value, columnIndex) {
                    return inlineStringCell(columnName(columnIndex + 1) + rowNumber, value, style);
                }).join('') + '</row>');
        });

        const mergedRanges = [
            'A1:' + finalColumn + '1',
            'B2:' + finalColumn + '2',
            'B3:' + finalColumn + '3',
            'B4:' + finalColumn + '4'
        ].map(function (range) {
            return '<mergeCell ref="' + range + '"/>';
        }).join('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
            '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' +
            '<dimension ref="A1:' + finalColumn + lastDataRow + '"/>' +
            '<sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" ' +
            'activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' +
            '<sheetFormatPr defaultRowHeight="18"/><cols>' + columns + '</cols><sheetData>' +
            rows.join('') + '</sheetData><autoFilter ref="A6:' + finalColumn + lastDataRow + '"/>' +
            '<mergeCells count="4">' + mergedRanges + '</mergeCells>' +
            '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>' +
            '<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>' +
            '</worksheet>';
    }

    function makeStyles() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
            '<fonts count="4">' +
            '<font><sz val="11"/><name val="Calibri"/></font>' +
            '<font><b/><sz val="16"/><color rgb="FF14532D"/><name val="Calibri"/></font>' +
            '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' +
            '<font><b/><sz val="10"/><color rgb="FF3F5147"/><name val="Calibri"/></font>' +
            '</fonts><fills count="4">' +
            '<fill><patternFill patternType="none"/></fill>' +
            '<fill><patternFill patternType="gray125"/></fill>' +
            '<fill><patternFill patternType="solid"><fgColor rgb="FF166534"/><bgColor indexed="64"/></patternFill></fill>' +
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F7F2"/><bgColor indexed="64"/></patternFill></fill>' +
            '</fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' +
            '<border><left style="thin"><color rgb="FFD5DED8"/></left>' +
            '<right style="thin"><color rgb="FFD5DED8"/></right>' +
            '<top style="thin"><color rgb="FFD5DED8"/></top>' +
            '<bottom style="thin"><color rgb="FFD5DED8"/></bottom><diagonal/></border></borders>' +
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' +
            '<cellXfs count="7">' +
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="center" wrapText="1"/></xf>' +
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' +
            '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>' +
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="center" wrapText="1"/></xf>' +
            '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0"><alignment vertical="center" wrapText="1"/></xf>' +
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="center" wrapText="1"/></xf>' +
            '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0"><alignment vertical="center" wrapText="1"/></xf>' +
            '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' +
            '</styleSheet>';
    }

    function crc32(bytes) {
        let crc = 0xFFFFFFFF;
        for (let index = 0; index < bytes.length; index++) {
            crc ^= bytes[index];
            for (let bit = 0; bit < 8; bit++) {
                crc = (crc >>> 1) ^ ((crc & 1) ? 0xEDB88320 : 0);
            }
        }
        return (crc ^ 0xFFFFFFFF) >>> 0;
    }

    function zipStore(files) {
        const localParts = [];
        const centralParts = [];
        let localOffset = 0;

        files.forEach(function (file) {
            const name = encoder.encode(file.name);
            const data = encoder.encode(file.content);
            const checksum = crc32(data);
            const localHeader = new Uint8Array(30 + name.length);
            const localView = new DataView(localHeader.buffer);
            localView.setUint32(0, 0x04034B50, true);
            localView.setUint16(4, 20, true);
            localView.setUint16(6, 0, true);
            localView.setUint16(8, 0, true);
            localView.setUint16(10, 0, true);
            localView.setUint16(12, 0x21, true);
            localView.setUint32(14, checksum, true);
            localView.setUint32(18, data.length, true);
            localView.setUint32(22, data.length, true);
            localView.setUint16(26, name.length, true);
            localView.setUint16(28, 0, true);
            localHeader.set(name, 30);
            localParts.push(localHeader, data);

            const centralHeader = new Uint8Array(46 + name.length);
            const centralView = new DataView(centralHeader.buffer);
            centralView.setUint32(0, 0x02014B50, true);
            centralView.setUint16(4, 20, true);
            centralView.setUint16(6, 20, true);
            centralView.setUint16(8, 0, true);
            centralView.setUint16(10, 0, true);
            centralView.setUint16(12, 0, true);
            centralView.setUint16(14, 0x21, true);
            centralView.setUint32(16, checksum, true);
            centralView.setUint32(20, data.length, true);
            centralView.setUint32(24, data.length, true);
            centralView.setUint16(28, name.length, true);
            centralView.setUint16(30, 0, true);
            centralView.setUint16(32, 0, true);
            centralView.setUint16(34, 0, true);
            centralView.setUint16(36, 0, true);
            centralView.setUint32(38, 0, true);
            centralView.setUint32(42, localOffset, true);
            centralHeader.set(name, 46);
            centralParts.push(centralHeader);
            localOffset += localHeader.length + data.length;
        });

        const centralSize = centralParts.reduce(function (total, part) {
            return total + part.length;
        }, 0);
        const endRecord = new Uint8Array(22);
        const endView = new DataView(endRecord.buffer);
        endView.setUint32(0, 0x06054B50, true);
        endView.setUint16(4, 0, true);
        endView.setUint16(6, 0, true);
        endView.setUint16(8, files.length, true);
        endView.setUint16(10, files.length, true);
        endView.setUint32(12, centralSize, true);
        endView.setUint32(16, localOffset, true);
        endView.setUint16(20, 0, true);

        return new Blob(localParts.concat(centralParts, [endRecord]), { type: workbookMimeType });
    }

    window.downloadReportWorkbook = function (report) {
        if (!report || !Array.isArray(report.headers) || report.headers.length < 1 ||
            !Array.isArray(report.rows) || !Array.isArray(report.columnWidths) ||
            report.columnWidths.length !== report.headers.length ||
            report.rows.some(function (row) { return !Array.isArray(row) || row.length !== report.headers.length; })) {
            throw new TypeError('The report data is not valid for Excel export.');
        }

        const finalColumn = columnName(report.headers.length);
        const files = [
            {
                name: '[Content_Types].xml',
                content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
                    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
                    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
                    '<Default Extension="xml" ContentType="application/xml"/>' +
                    '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
                    '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' +
                    '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' +
                    '</Types>'
            },
            {
                name: '_rels/.rels',
                content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
                    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
                    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
                    '</Relationships>'
            },
            {
                name: 'xl/workbook.xml',
                content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
                    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' +
                    'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
                    '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>'
            },
            {
                name: 'xl/_rels/workbook.xml.rels',
                content: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
                    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
                    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' +
                    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' +
                    '</Relationships>'
            },
            { name: 'xl/styles.xml', content: makeStyles() },
            { name: 'xl/worksheets/sheet1.xml', content: makeWorksheet(report) }
        ];
        const blob = zipStore(files);
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = report.filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    };
}());
