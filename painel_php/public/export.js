/* Public downloads. PDF generation is local; no competition data leaves this site. */
(() => {
  'use strict';
  const dialog = document.querySelector('#export-dialog');
  const triggers = document.querySelectorAll('[data-export-open]');
  if (dialog && typeof dialog.showModal === 'function') {
    triggers.forEach(trigger => trigger.addEventListener('click', event => { event.preventDefault(); dialog.showModal(); }));
    dialog.querySelector('[data-export-close]').addEventListener('click', () => dialog.close());
  }
  let pdfLibrary;
  function script(src) {
    return new Promise((resolve, reject) => {
      const tag = document.createElement('script'); tag.src = src;
      tag.onload = resolve;
      tag.onerror = () => { tag.remove(); reject(new Error('Não foi possível carregar o gerador de PDF. Tente novamente.')); };
      document.head.append(tag);
    });
  }
  function loadPdf() {
    if (!pdfLibrary) pdfLibrary = (async () => {
      if (!window.pdfMake) await script('vendor/pdfmake/pdfmake.min.js?v=0.3.11');
      await script('vendor/pdfmake/vfs_fonts.js?v=0.3.11');
      // All images and fonts are embedded; external URLs are never needed.
      pdfMake.setUrlAccessPolicy(() => false);
    })().catch(error => { pdfLibrary = null; throw error; });
    return pdfLibrary;
  }
  // The encounter snapshot stays in memory; only local logos/fonts are loaded.
  window.copaMeetingPdf = async report => {
    await loadPdf();
    const logos=await Promise.all(['.header-logo img','.league-logo'].map(async selector=>{
      const response=await fetch(document.querySelector(selector).src);
      if(!response.ok) throw new Error('Logo indisponivel');
      const blob=await response.blob();
      return new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=reject;reader.readAsDataURL(blob);});
    }));
    const green='#123e32';
    const table=(headers,rows,widths)=>({table:{headerRows:1,keepWithHeaderRows:1,dontBreakRows:true,widths,body:[headers.map(text=>({text,bold:true,color:'white',fillColor:green})),...rows.map((row,i)=>row.map(text=>({text:String(text),fillColor:i%2?'#ffffff':'#eff4ed'})))]},layout:{hLineWidth:()=>0.5,vLineWidth:()=>0,hLineColor:()=>'#c6d2c7',paddingTop:()=>8,paddingBottom:()=>8,paddingLeft:()=>7,paddingRight:()=>7},margin:[0,0,0,16]});
    const content=[{text:'Organizar encontro',fontSize:22,bold:true,margin:[0,0,0,10]},
      {text:report.turn,fontSize:16,bold:true,margin:[0,0,0,8]},
      {text:report.details.join('  |  ') || 'Data, horário e local a combinar.',margin:[0,0,0,10]},
      {text:`${report.players.length} participantes - ${report.games.length} partidas pendentes`,bold:true,margin:[0,0,0,12]},
      {text:'Simulação de confrontos sem resultado registrado. Não agenda partidas nem altera resultados. Se um jogo já aconteceu, confira se o placar foi informado.',fontSize:12,margin:[0,0,0,16]},
      {text:'Participantes',fontSize:18,bold:true,margin:[0,0,0,8]},
      table(['Botonista','Partidas'],report.players.map(p=>[p.name,report.games.filter(g=>g.a===p.name||g.b===p.name).length]),['*',70])];
    for(const turn of [1,2]) {
      const games=report.games.filter(g=>Number(g.turn)===turn);if(!games.length)continue;
      content.push({text:turn===1?'Confrontos - 1º turno':'Confrontos - 2º turno (returno)',fontSize:18,bold:true,margin:[0,8,0,12],pageBreak:'before'});
      content.push(table(['Rodada','Jogador A','Jogador B'],games.map(g=>[g.round,g.a,g.b]),[55,'*','*']));
    }
    return pdfMake.createPdf({info:{title:'Copa Lago de Pedra - Encontro',creator:'Copa Lago de Pedra'},images:{copa:logos[0],liga:logos[1]},pageSize:'A4',pageMargins:[36,112,36,54],defaultStyle:{font:'Roboto',fontSize:14,color:green,lineHeight:1.15},
      header:()=>({margin:[36,18,36,0],columns:[{image:'copa',fit:[65,70],width:75},{width:'*',stack:[{text:'I COPA LAGO DE PEDRA',alignment:'center',bold:true,fontSize:16,margin:[0,10,0,6]},{text:'Futebol de Botão | Planejamento de encontro',alignment:'center',fontSize:11}]},{image:'liga',fit:[60,70],width:60}]}),
      footer:(page,pages)=>({margin:[36,12,36,0],columns:[{text:'Gerado em '+report.generated,fontSize:10},{text:`Página ${page} de ${pages}`,alignment:'right',fontSize:10}]}),content}).getBlob();
  };
  function pdfDefinition(report) {
    const green = '#173e33';
    const content = [];
    for (const [index, section] of report.sections.entries()) {
      content.push({text:section.title,fontSize:18,bold:true,color:green,margin:[0,0,0,8],pageBreak:index?'before':undefined});
      content.push({text:section.note,fontSize:10,margin:[0,0,0,10]});
      const sum = section.widths.reduce((a,b) => a+b,0);
      // Printable A4 landscape width, minus cell padding and borders.
      const tableWidth = 841.89 - 64 - section.widths.length * 8 - (section.widths.length+1)*0.5;
      const body = [section.headers.map(text => ({text,bold:true,color:'#ffffff',fillColor:green}))];
      section.rows.forEach((row, r) => body.push(row.map((value,c) => ({text:String(value),alignment:section.left.includes(c)?'left':'center',fillColor:r%2?'#ffffff':'#f0f5f1'}))));
      const padding = section.headers.length === 11 ? 0.5 : 4;
      content.push({table:{headerRows:1,keepWithHeaderRows:1,dontBreakRows:true,widths:section.widths.map(w => w/sum*tableWidth),body},layout:{hLineWidth:()=>0.5,vLineWidth:()=>0,hLineColor:()=> '#c9d5cf',paddingLeft:()=>4,paddingRight:()=>4,paddingTop:()=>padding,paddingBottom:()=>padding}});
    }
    return {
      info:{title:report.title,subject:'Classificação e jogos da competição',creator:'Copa Lago de Pedra'},
      pageSize:'A4',pageOrientation:'landscape',pageMargins:[32,96,32,55],
      defaultStyle:{font:'Roboto',fontSize:11,color:green},
      header:() => ({margin:[32,16,32,0],columns:[{image:report.logos[0],fit:[60,60],width:70},{width:'*',stack:[{text:report.title,fontSize:17,bold:true,alignment:'center',margin:[0,6,0,8]},{text:report.generated,fontSize:9,alignment:'center'}]},{image:report.logos[1],fit:[60,60],width:60}]}),
      footer:(page,pages) => ({margin:[32,12,32,0],stack:[{text:report.note,fontSize:8,alignment:'center'},{text:`Página ${page} de ${pages}`,fontSize:9,alignment:'center',margin:[0,5,0,0]}]}),
      content
    };
  }
  document.querySelectorAll('.export-form').forEach(form => {
    form.querySelector('option[value="pdf"]').disabled = false;
    let objectUrl;
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const submit = form.querySelector('button[type="submit"]');
      if (submit.disabled) return;
      const status = form.querySelector('.export-status');
      const ready = form.querySelector('.export-ready');
      const format = form.elements.format.value;
      const query = new URLSearchParams(new FormData(form));
      submit.disabled = true; form.setAttribute('aria-busy','true');
      status.dataset.error = 'false'; status.textContent = 'Preparando seu arquivo. Aguarde…'; ready.hidden = true;
      if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
      try {
        const response = await fetch(`${form.action}?${query}`, {cache:'no-store'});
        if (!response.ok) throw new Error('Não foi possível gerar o arquivo. Tente novamente.');
        let blob, filename;
        if (format === 'pdf') {
          const report = await response.json();
          await loadPdf();
          blob = await pdfMake.createPdf(pdfDefinition(report)).getBlob();
          filename = report.filename + '.pdf';
        } else {
          blob = await response.blob();
          filename = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') || '')?.[1] || `copa-lago-de-pedra.${format}`;
        }
        objectUrl = URL.createObjectURL(blob); ready.href = objectUrl; ready.download = filename; ready.hidden = false;
        ready.click();
        status.textContent = 'Arquivo preparado. Se o download não começar, toque em “Salvar arquivo preparado”.';
      } catch (error) {
        status.dataset.error = 'true'; status.textContent = 'Não foi possível preparar o arquivo. Confira sua conexão e tente novamente.';
      } finally {
        submit.disabled = false; form.removeAttribute('aria-busy');
      }
    });
  });
})();
