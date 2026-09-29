<?php
declare(strict_types=1);

// Only public competition fields enter the downloadable documents.
function competitionReport(array $data, DateTimeImmutable $generated, string $content): array
{
    $sections=[];
    if ($content !== 'games') {
        $rows=[];
        foreach ($data['players'] as $p) $rows[]=[(string)$p['position'],$p['name'],(string)$p['points'],(string)$p['played'],(string)$p['wins'],(string)$p['draws'],(string)$p['losses'],(string)$p['goalsFor'],(string)$p['goalsAgainst'],(string)$p['goalDifference'],$p['played']?number_format($p['points']/(3*$p['played'])*100,1,',','').'%':'—'];
        $sections[]=['title'=>'Classificação','note'=>'Critérios: PG, depois V, SG e GM. Vitória: 3 pontos; empate: 1 ponto. Aproveitamento —: ainda sem jogos.','headers'=>['Pos.','Botonista','PG','J','V','E','D','GM','GS','SG','Aprov.'],'widths'=>[6,30,6,6,6,6,6,6,6,6,10],'left'=>[1],'rows'=>$rows];
    }
    if ($content !== 'ranking') {
        $rows=[];
        foreach ($data['games'] as $g) {
            $played=$g['status']==='played';
            $date=$g['playedAt'] && validGameDate($g['playedAt']) ? (new DateTimeImmutable($g['playedAt']))->format('d/m/Y') : '';
            $rows[]=[(string)$g['round'],(string)$g['game'],$g['turn']===1?'Turno':'Returno',$g['a'],$played?(string)$g['scoreA']:'',$played?(string)$g['scoreB']:'',$g['b'],$date,$played?'Com resultado':'Sem resultado'];
        }
        $sections[]=['title'=>'Jogos e resultados','note'=>'Gols em branco: sem resultado registrado. Data em branco: não informada. 0 × 0 é um empate registrado.','headers'=>['Rod.','Jogo','Etapa','Jogador A','Gols A','Gols B','Jogador B','Data','Situação'],'widths'=>[5,5,8,23,5,5,23,11,15],'left'=>[3,6],'rows'=>$rows];
    }
    $logos=[];
    foreach (['lago_de_pedra_256.png','liga_mogiana_256.png'] as $name) {
        $bytes=file_get_contents(dirname(__DIR__).'/site/'.$name);
        if ($bytes===false) throw new RuntimeException('Logo indisponível.');
        $logos[]='data:image/png;base64,'.base64_encode($bytes);
    }
    return ['title'=>'I Copa Lago de Pedra de Futebol de Botão','generated'=>'Gerado em '.$generated->format('d/m/Y H:i:s').' ('.$generated->getTimezone()->getName().')','note'=>'Fonte: resultados registrados no sistema da competição. Cópia do momento do download; sem filtros da tela.','filename'=>'copa-lago-de-pedra-'.$content.'-'.$generated->format('Ymd-His'),'logos'=>$logos,'sections'=>$sections];
}

function reportWordParagraph(string $text, bool $bold=false, int $size=22, string $align='left', string $color='173E33'): string
{
    return '<w:p><w:pPr><w:jc w:val="'.$align.'"/><w:spacing w:after="60"/></w:pPr><w:r><w:rPr>'.($bold?'<w:b/>':'').'<w:color w:val="'.$color.'"/><w:sz w:val="'.$size.'"/></w:rPr><w:t xml:space="preserve">'.spreadsheetXml($text).'</w:t></w:r></w:p>';
}

function reportWordLogo(int $id): string
{
    return '<w:p><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="640080" cy="640080"/><wp:docPr id="'.$id.'" name="Logo '.$id.'" descr="'.($id===1?'Copa Lago de Pedra':'Liga Mogiana').'"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="'.$id.'" name="logo'.$id.'.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="logo'.$id.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="640080" cy="640080"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
}

function competitionWord(array $report): string
{
    $ns='xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';
    $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $body='';
    foreach ($report['sections'] as $i=>$section) {
        $body.='<w:p><w:pPr><w:keepNext/>'.($i?'<w:pageBreakBefore/>':'').'<w:spacing w:after="140"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="32"/><w:color w:val="173E33"/></w:rPr><w:t>'.spreadsheetXml($section['title']).'</w:t></w:r></w:p>';
        $body.=reportWordParagraph($section['note'],false,20);
        $body.='<w:tbl><w:tblPr><w:tblW w:w="15110" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="C9D5CF"/><w:bottom w:val="single" w:sz="4" w:color="C9D5CF"/><w:insideH w:val="single" w:sz="4" w:color="C9D5CF"/></w:tblBorders><w:tblCellMar><w:top w:w="70" w:type="dxa"/><w:left w:w="65" w:type="dxa"/><w:bottom w:w="70" w:type="dxa"/><w:right w:w="65" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        $widths=array_map(fn($v)=>(int)round($v/array_sum($section['widths'])*15110),$section['widths']);
        foreach ($widths as $width) $body.='<w:gridCol w:w="'.$width.'"/>';
        $body.='</w:tblGrid>';
        foreach (array_merge([$section['headers']],$section['rows']) as $rowIndex=>$row) {
            $header=$rowIndex===0;
            $body.='<w:tr><w:trPr><w:cantSplit/>'.($header?'<w:tblHeader/>':'').'</w:trPr>';
            foreach ($row as $j=>$value) {
                $fill=$header?'173E33':($rowIndex%2?'F0F5F1':'FFFFFF');
                $body.='<w:tc><w:tcPr><w:tcW w:w="'.$widths[$j].'" w:type="dxa"/><w:shd w:fill="'.$fill.'"/><w:vAlign w:val="center"/></w:tcPr>'.reportWordParagraph((string)$value,$header,22,in_array($j,$section['left'],true)?'left':'center',$header?'FFFFFF':'173E33').'</w:tc>';
            }
            $body.='</w:tr>';
        }
        $body.='</w:tbl>'.reportWordParagraph('');
    }
    $body.='<w:sectPr><w:headerReference w:type="default" r:id="header"/><w:footerReference w:type="default" r:id="footer"/><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="1550" w:right="864" w:bottom="950" w:left="864" w:header="300" w:footer="300"/></w:sectPr>';
    $header=$xml.'<w:hdr '.$ns.'><w:tbl><w:tblPr><w:tblW w:w="15110" w:type="dxa"/><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid><w:gridCol w:w="1200"/><w:gridCol w:w="12710"/><w:gridCol w:w="1200"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcW w:w="1200" w:type="dxa"/></w:tcPr>'.reportWordLogo(1).'</w:tc><w:tc><w:tcPr><w:tcW w:w="12710" w:type="dxa"/></w:tcPr>'.reportWordParagraph($report['title'],true,28,'center').reportWordParagraph($report['generated'],false,18,'center').'</w:tc><w:tc><w:tcPr><w:tcW w:w="1200" w:type="dxa"/></w:tcPr>'.reportWordLogo(2).'</w:tc></w:tr></w:tbl>'.reportWordParagraph('').'</w:hdr>';
    $rels='http://schemas.openxmlformats.org/package/2006/relationships';
    $type='http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
    return spreadsheetZip([
        '[Content_Types].xml'=>$xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/></Types>',
        '_rels/.rels'=>$xml.'<Relationships xmlns="'.$rels.'"><Relationship Id="document" Type="'.$type.'officeDocument" Target="word/document.xml"/></Relationships>',
        'word/document.xml'=>$xml.'<w:document '.$ns.'><w:body>'.$body.'</w:body></w:document>',
        'word/styles.xml'=>$xml.'<w:styles '.$ns.'><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/><w:lang w:val="pt-BR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="60"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>',
        'word/_rels/document.xml.rels'=>$xml.'<Relationships xmlns="'.$rels.'"><Relationship Id="styles" Type="'.$type.'styles" Target="styles.xml"/><Relationship Id="header" Type="'.$type.'header" Target="header1.xml"/><Relationship Id="footer" Type="'.$type.'footer" Target="footer1.xml"/></Relationships>',
        'word/header1.xml'=>$header,
        'word/footer1.xml'=>$xml.'<w:ftr '.$ns.'>'.reportWordParagraph($report['note'],false,16,'center').'<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t xml:space="preserve">Página </w:t></w:r><w:fldSimple w:instr="PAGE"/><w:r><w:t xml:space="preserve"> de </w:t></w:r><w:fldSimple w:instr="NUMPAGES"/></w:p></w:ftr>',
        'word/_rels/header1.xml.rels'=>$xml.'<Relationships xmlns="'.$rels.'"><Relationship Id="logo1" Type="'.$type.'image" Target="media/logo1.png"/><Relationship Id="logo2" Type="'.$type.'image" Target="media/logo2.png"/></Relationships>',
        'word/media/logo1.png'=>base64_decode(explode(',',$report['logos'][0],2)[1],true),
        'word/media/logo2.png'=>base64_decode(explode(',',$report['logos'][1],2)[1],true),
    ]);
}
