const SERIES = [
    {key:'checkouts', label:'عدد الأوردرات', color:'#4f46e5'},
    {key:'story_pieces', label:'قطع القصص', color:'#9333ea'},
    {key:'product_pieces', label:'قطع المنتجات', color:'#0891b2'},
    {key:'shipped_checkouts', label:'اتشحنت خلال الشهر', color:'#059669'},
    {key:'average_order_cents', label:'متوسط قيمة الأوردر', color:'#d97706', money:true},
];
const number = new Intl.NumberFormat('ar-EG', {maximumFractionDigits:2});
const svgNode = (tag, attrs={}, text=null) => {
    const node=document.createElementNS('http://www.w3.org/2000/svg',tag);
    Object.entries(attrs).forEach(([k,v])=>node.setAttribute(k,String(v)));
    if(text!==null) node.textContent=text;
    return node;
};
export function initializeMonthComparison(root) {
    if(root.dataset.ready) return;
    let months;
    try { months=JSON.parse(root.dataset.months); } catch { return; }
    if(!Array.isArray(months)||months.length!==6) return;
    const visible=new Set(SERIES.map(s=>s.key));
    const plot=root.querySelector('[data-month-plot]'), detail=root.querySelector('[data-month-detail]'), legend=root.querySelector('[data-month-legend]');
    let selected=5;
    function describe() {
        const month=months[selected];
        const heading=document.createElement('strong');heading.className='mb-3 block text-slate-900';
        heading.textContent=month.label+(month.partial?' — حتى الآن':'');
        const values=document.createElement('div');values.className='flex flex-wrap';values.style.gap='12px 24px';
        SERIES.forEach(s=>{const value=document.createElement('span');value.style.color=s.color;
            value.textContent=s.label+': '+(month[s.key]===null?'—':number.format(month[s.key]/(s.money?100:1))+(s.money?' ج.م':''));values.append(value);});
        detail.replaceChildren(heading,values);
    }
    function draw() {
        const width=Math.max(280,plot.clientWidth), height=320, left=48,right=62,top=34,bottom=60;
        const inner=width-left-right, range=height-top-bottom;
        const countMax=Math.max(1,...SERIES.filter(s=>!s.money).flatMap(s=>months.map(m=>m[s.key])));
        const moneyMax=Math.max(1,...months.map(m=>(m.average_order_cents||0)/100));
        const niceMax=v=>{const step=10**Math.floor(Math.log10(v));return Math.ceil(v/step)*step;};
        const countScale=Math.max(4, Math.ceil(niceMax(countMax)/4)*4),moneyScale=niceMax(moneyMax);
        const x=i=>left+inner*i/5;
        const y=(v,s)=>top+range-(v/(s.money?100:1))/(s.money?moneyScale:countScale)*range;
        const svg=svgNode('svg',{viewBox:`0 0 ${width} ${height}`,width:'100%',height,role:'group','aria-label':'خطوط المقارنة الشهرية، استخدم الأسهم للتنقل بين الشهور',dir:'ltr'});
        svg.append(svgNode('text',{x:left,y:16,fill:'#64748b','font-size':11,'text-anchor':'middle'},'العدد'));
        svg.append(svgNode('text',{x:width-right,y:16,fill:'#b45309','font-size':11,'text-anchor':'middle'},'متوسط ج.م'));
        for(let i=0;i<=4;i++) {
            const pos=top+range-i*range/4;
            svg.append(svgNode('line',{x1:left,x2:width-right,y1:pos,y2:pos,stroke:'#e2e8f0'}));
            svg.append(svgNode('text',{x:left-8,y:pos+4,'text-anchor':'end','font-size':10,fill:'#64748b'},number.format(countScale*i/4)));
            svg.append(svgNode('text',{x:width-right+8,y:pos+4,'text-anchor':'start','font-size':10,fill:'#b45309'},number.format(moneyScale*i/4)));
        }
        svg.append(svgNode('line',{x1:x(selected),x2:x(selected),y1:top,y2:height-bottom,stroke:'#cbd5e1','stroke-dasharray':'3 4'}));
        SERIES.filter(s=>visible.has(s.key)).forEach(s=>{
            let d='', active=false;
            months.forEach((month,i)=>{if(month[s.key]===null){active=false;return;}d+=`${active?'L':'M'}${x(i)} ${y(month[s.key],s)} `;active=true;});
            svg.append(svgNode('path',{d,fill:'none',stroke:s.color,'stroke-width':2.5,'stroke-linejoin':'round',...(s.money?{'stroke-dasharray':'6 4'}:{})}));
            months.forEach((m,i)=>{if(m[s.key]!==null)svg.append(svgNode('circle',{cx:x(i),cy:y(m[s.key],s),r:3.5,fill:'white',stroke:s.color,'stroke-width':2}));});
        });
        months.forEach((m,i)=>{
            svg.append(svgNode('text',{x:x(i),y:height-bottom+23,'text-anchor':'middle','font-size':width<500?10:12,fill:'#475569'},m.short_label));
            svg.append(svgNode('text',{x:x(i),y:height-bottom+40,'text-anchor':'middle','font-size':10,fill:'#94a3b8'},number.format(m.year).replace(/[٬,]/g,'')));
            const hit=svgNode('rect',{x:Math.max(left-12,x(i)-inner/10),y:top,width:i===0||i===5?inner/10+12:inner/5,height:range,fill:'transparent',tabindex:0,role:'button','aria-label':m.label+(m.partial?'، حتى الآن':'')});
            const choose=()=>{selected=i;describe();const line=svg.querySelector('line[stroke-dasharray]');if(line){line.setAttribute('x1',x(i));line.setAttribute('x2',x(i));}};
            hit.addEventListener('mouseenter',choose);hit.addEventListener('focus',choose);hit.addEventListener('click',choose);
            hit.addEventListener('keydown',e=>{if(['ArrowRight','ArrowLeft','Home','End','Enter',' '].includes(e.key)){e.preventDefault();const targets=[...svg.querySelectorAll('[role=button]')];const next=e.key==='Home'?0:e.key==='End'?5:e.key==='ArrowRight'?Math.min(5,i+1):e.key==='ArrowLeft'?Math.max(0,i-1):i;targets[next].focus();}});
            svg.append(hit);
        });
        plot.replaceChildren(svg);
    }
    SERIES.forEach(s=>{
        const button=document.createElement('button');button.type='button';button.className='rounded-full border px-3 py-2 text-xs font-bold';button.style.color=s.color;button.style.borderColor=s.color;
        button.setAttribute('aria-pressed','true');button.textContent=(s.money?'┄ ':'━ ')+s.label;
        button.addEventListener('click',()=>{visible.has(s.key)?visible.delete(s.key):visible.add(s.key);button.setAttribute('aria-pressed',String(visible.has(s.key)));button.style.opacity=visible.has(s.key)?'1':'.45';draw();});legend.append(button);
    });
    describe();draw();new ResizeObserver(draw).observe(plot);root.dataset.ready='true';
}
