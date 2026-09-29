<?php
declare(strict_types=1);

// Minimal SpreadsheetML writer: typed cells, no formulas, macros or external links.
// https://learn.microsoft.com/en-us/office/open-xml/spreadsheet/structure-of-a-spreadsheetml-document
function spreadsheetXml(string $text): string
{
    $text=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$text) ?? '';
    return htmlspecialchars($text,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function spreadsheetCell(string $address, mixed $value, int $style=0): string
{
    if ($value===null) return '<c r="'.$address.'" s="'.$style.'"/>';
    if (is_int($value) || is_float($value)) return '<c r="'.$address.'" s="'.$style.'"><v>'.json_encode($value,JSON_THROW_ON_ERROR).'</v></c>';
    return '<c r="'.$address.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.spreadsheetXml((string)$value).'</t></is></c>';
}

function spreadsheetSheet(string $title, string $generated, string $note, array $headers, array $rows, array $widths): string
{
    $last=chr(64+count($headers)); $end=count($rows)+4;
    $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:'.$last.$end.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A5" sqref="A5"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="22"/><cols>';
    foreach ($widths as $i=>$width) $xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';
    $xml.='</cols><sheetData><row r="1" ht="28" customHeight="1">'.spreadsheetCell('A1',$title,1).'</row><row r="2">'.spreadsheetCell('A2',$generated,2).'</row><row r="3">'.spreadsheetCell('A3',$note,2).'</row><row r="4" ht="32" customHeight="1">';
    foreach ($headers as $i=>$header) $xml.=spreadsheetCell(chr(65+$i).'4',$header,3);
    $xml.='</row>';
    foreach ($rows as $i=>$row) {
        $r=$i+5;$xml.='<row r="'.$r.'">';
        foreach ($row as $j=>$cell) $xml.=spreadsheetCell(chr(65+$j).$r,$cell[0],$cell[1]??0);
        $xml.='</row>';
    }
    return $xml.'</sheetData><autoFilter ref="A4:'.$last.$end.'"/><mergeCells count="3"><mergeCell ref="A1:'.$last.'1"/><mergeCell ref="A2:'.$last.'2"/><mergeCell ref="A3:'.$last.'3"/></mergeCells><pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
}

// ZIP STORE avoids requiring ZipArchive or additional software on shared hosting.
function spreadsheetZip(array $files): string
{
    $body='';$directory='';
    foreach ($files as $name=>$bytes) {
        $offset=strlen($body);$size=strlen($bytes);$crc=crc32($bytes);$length=strlen($name);
        $body.=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$size,$size,$length,0).$name.$bytes;
        $directory.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$size,$size,$length,0,0,0,0,0,$offset).$name;
    }
    return $body.$directory.pack('VvvvvVVv',0x06054b50,0,0,count($files),count($files),strlen($directory),strlen($body),0);
}

function competitionSpreadsheet(array $data, DateTimeImmutable $generated, string $content='all'): string
{
    $stamp='Gerado em '.$generated->format('d/m/Y H:i:s').' ('.$generated->getTimezone()->getName().') — competição completa; cópia do momento do download.';
    $ranking=[];
    foreach ($data['players'] as $p) $ranking[]=[[$p['position'],4],[$p['name']],[$p['points'],4],[$p['played'],4],[$p['wins'],4],[$p['draws'],4],[$p['losses'],4],[$p['goalsFor'],4],[$p['goalsAgainst'],4],[$p['goalDifference'],4],[$p['played']?$p['points']/(3*$p['played']):null,6]];
    $games=[];$epoch=new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC'));
    foreach ($data['games'] as $g) {
        $date=null;
        if ($g['playedAt'] && validGameDate($g['playedAt'])) $date=(int)$epoch->diff(new DateTimeImmutable($g['playedAt'],new DateTimeZone('UTC')))->format('%r%a');
        $played=$g['status']==='played';
        $games[]=[[$g['round'],4],[$g['game'],4],[$g['turn']===1?'Turno':'Returno'],[$g['a']],[$played?$g['scoreA']:null,4],[$played?$g['scoreB']:null,4],[$g['b']],[$date,5],[$played?'Com resultado':'Sem resultado',$played?9:8]];
    }
    $styles=<<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts><fonts count="4"><font><sz val="11"/><name val="Calibri"/><color rgb="FF202D29"/></font><font><b/><sz val="14"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font><font><sz val="10"/><name val="Calibri"/><color rgb="FF606E66"/></font><font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF123E32"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF123E32"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE4EFD9"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF2D5"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="10"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1"/><xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFill="1" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>
XML;
    $files=[
        '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="Classificação" sheetId="1" r:id="rId1"/><sheet name="Jogos e resultados" sheetId="2" r:id="rId2"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml'=>$styles,
        'xl/worksheets/sheet1.xml'=>spreadsheetSheet('I Copa Lago de Pedra — Classificação',$stamp,'Critérios: PG → V → SG → GM. Vitória: 3 pontos; empate: 1 ponto. Aproveitamento vazio: ainda sem jogos.',['Posição','Botonista','PG','J','V','E','D','GM','GS','SG','Aproveitamento'],$ranking,[10,36,9,9,9,9,9,9,9,9,19]),
        'xl/worksheets/sheet2.xml'=>spreadsheetSheet('I Copa Lago de Pedra — Jogos e resultados',$stamp,'Placar vazio: sem resultado registrado. Data vazia: não informada. Inclui todos os jogos, sem aplicar filtros da tela.',['Rodada','Jogo','Etapa','Jogador A','Gols A','Gols B','Jogador B','Data da partida','Situação'],$games,[10,10,14,36,10,10,36,19,20]),
    ];
    if ($content==='ranking' || $content==='games') {
        $remove=$content==='ranking'?2:1;
        unset($files['xl/worksheets/sheet'.$remove.'.xml']);
        $files['[Content_Types].xml']=preg_replace('~<Override PartName="/xl/worksheets/sheet'.$remove.'\.xml"[^>]*/>~','',$files['[Content_Types].xml']);
        $files['xl/workbook.xml']=preg_replace('~<sheet name="[^"]*" sheetId="'.$remove.'"[^>]*/>~','',$files['xl/workbook.xml']);
        $files['xl/_rels/workbook.xml.rels']=preg_replace('~<Relationship Id="rId'.$remove.'"[^>]*/>~','',$files['xl/_rels/workbook.xml.rels']);
    }
    return spreadsheetZip($files);
}
