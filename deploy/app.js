(()=>{const h=React.createElement,D=window.TSB_DATA,LS='tsb_os_demo_v2';
const nav=['Dashboard','Tea','Purchase & Suppliers','Blending & Production','QC & Wastage','Packaging','Products / SKU','Pricing Engine','Inventory / Warehouse','Outlets / Franchise','Franchise & Retail Operations','POS / Sales','Margin & Settlement','Finance & Accounts','Profit & Loss','Performance & Incentives','Customers','Corporate / B2B','Logistics','Reports','Approvals','Documents','Notifications','Users & Roles','Audit Log','Settings'];
const access={
 OWNER:nav,
 OPERATIONS:['Dashboard','Tea','Inventory / Warehouse','Outlets / Franchise','Franchise & Retail Operations','POS / Sales','Margin & Settlement','Performance & Incentives','Customers','Logistics','Reports','Notifications'],
 FINANCE:['Dashboard','Tea','Purchase & Suppliers','Margin & Settlement','Finance & Accounts','Profit & Loss','Performance & Incentives','Corporate / B2B','Reports','Approvals','Documents','Notifications'],
 WAREHOUSE:['Dashboard','Tea','Purchase & Suppliers','Blending & Production','QC & Wastage','Packaging','Products / SKU','Inventory / Warehouse','Logistics','Reports','Notifications'],
 PRODUCTION:['Dashboard','Tea','Blending & Production','QC & Wastage','Products / SKU','Inventory / Warehouse','Reports','Notifications'],
 QC:['Dashboard','Tea','Blending & Production','QC & Wastage','Products / SKU','Reports','Notifications'],
 PACKAGING:['Dashboard','Tea','Packaging','Products / SKU','Inventory / Warehouse','Reports','Notifications'],
 REGIONAL:['Dashboard','Tea','Inventory / Warehouse','Outlets / Franchise','Franchise & Retail Operations','Reports','Notifications'],
 FRANCHISE:['Dashboard','Tea','Inventory / Warehouse','Outlets / Franchise','POS / Sales','Margin & Settlement','Customers','Notifications'],
 OUTLET_MANAGER:['Dashboard','Tea','Inventory / Warehouse','POS / Sales','Margin & Settlement','Customers','Notifications'],
 CASHIER:['POS / Sales','Customers'],
 AUDITOR:['Dashboard','Tea','Purchase & Suppliers','Blending & Production','QC & Wastage','Packaging','Products / SKU','Pricing Engine','Inventory / Warehouse','Outlets / Franchise','Margin & Settlement','Finance & Accounts','Profit & Loss','Performance & Incentives','Corporate / B2B','Logistics','Reports','Approvals','Documents','Audit Log']
};
const initial={sales:[],purchases:[],production:[],packages:[],transfers:[],franchises:[],expenses:[],settings:{marginTier:'Starter',manualMargin:30,managementTier:'Base',managementManual:20,...D.assumptions}};
function load(){try{return {...initial,...JSON.parse(localStorage.getItem(LS)||'{}')}}catch{return initial}}
function save(s){localStorage.setItem(LS,JSON.stringify(s))}
const money=n=>'৳'+Math.round(Number(n||0)).toLocaleString('en-BD');
const round10=n=>Math.ceil(n/10)*10;
function packCost(cat,g,a){if(cat==='CTC / Black Tea'){if(g===250)return a.ctc250;if(g===500)return a.ctc500;if(g===1000)return a.ctc500*2;if(g===2000)return a.ctc500*4;if(g===5000)return a.ctc500*10}if(g===30)return a.tube30;if(g===50)return a.pouch50;if(g===100)return a.pouch100;if(g===200)return a.pouch100*2;if(g===250)return a.pouch100*2+a.pouch50;return 0}
function economics(p,g,s,margin){const raw=p.costKg*g/1000,pack=packCost(p.category,g,s),waste=raw*(s.wastagePct/100),landed=raw+pack+s.labour+s.overhead+s.logistics+waste,min=round10(landed/(1-(margin/100)-.15));return{raw,pack,waste,landed,min,fr:min*margin/100,company:min*(1-margin/100)}}
function Field({label,value,onChange,type='text',step}){return h('label',{className:'field'},label,h('input',{type,step,value,onChange:e=>onChange(e.target.value)}))}
function Pill({children,kind=''}){return h('span',{className:'pill '+kind},children)}
function Empty({text}){return h('div',{className:'empty'},text)}
let csrfToken='';
async function api(route,{method='GET',body}={}){
 const headers={'Accept':'application/json'};
 if(body!==undefined)headers['Content-Type']='application/json';
 if(csrfToken&&method!=='GET')headers['X-CSRF-Token']=csrfToken;
 const parts=String(route).split('?'),routeName=parts.shift(),query=parts.join('?');
 const url='/api/index.php?route='+encodeURIComponent(routeName)+(query?'&'+query:'');
 const res=await fetch(url,{
  method,headers,credentials:'same-origin',body:body===undefined?undefined:JSON.stringify(body)
 });
 let data={};try{data=await res.json()}catch{}
 if(!res.ok||data.ok===false){const err=new Error(data.code||('HTTP_'+res.status));err.code=data.code||'API_ERROR';throw err}
 if(data.csrf)csrfToken=data.csrf;
 return data;
}
function Login({onLogin}){const[email,setEmail]=React.useState('owner@teashop.bd'),[password,setPassword]=React.useState(''),[busy,setBusy]=React.useState(false),[error,setError]=React.useState('');
async function submit(e){e.preventDefault();setBusy(true);setError('');try{const r=await api('login',{method:'POST',body:{email,password}});onLogin(r.user)}catch(err){setError(err.code==='INVALID_LOGIN'?'Email or password is incorrect.':'Unable to sign in right now.')}finally{setBusy(false)}}
return h('div',{className:'login'},h('section',{className:'loginIntro'},h('div',{className:'logo'},'T'),h('small',null,'TEA SHOP BD · BUSINESS OS'),h('h1',null,'One command center.',h('br'),'Every outlet accountable.'),h('p',null,'Raw tea, production, packaging, inventory, franchise, POS, settlement and finance—under founder control.')),
h('form',{className:'loginCard',onSubmit:submit},h('div',{className:'logo mini'},'T'),h('h2',null,'Welcome back'),h('p',null,'Sign in with your Tea Shop BD Business OS account.'),error?h('div',{className:'authError'},error):null,h(Field,{label:'Email',value:email,onChange:setEmail}),h(Field,{label:'Password',value:password,onChange:setPassword,type:'password'}),h('button',{className:'primary',disabled:busy||!email||!password},busy?'Signing in…':'Sign in →'),h('div',{className:'secureNote'},'Secure database authentication · role-based access · audit logging')))}
function ChangePassword({user,onDone,onLogout}){const[current,setCurrent]=React.useState(''),[next,setNext]=React.useState(''),[confirm,setConfirm]=React.useState(''),[busy,setBusy]=React.useState(false),[error,setError]=React.useState('');
async function submit(e){e.preventDefault();setError('');if(next!==confirm){setError('New passwords do not match.');return}if(next.length<10){setError('Use at least 10 characters.');return}setBusy(true);try{const r=await api('password.change',{method:'POST',body:{current_password:current,new_password:next}});onDone(r.user)}catch(err){const m={CURRENT_PASSWORD_INVALID:'Current password is incorrect.',PASSWORD_TOO_SHORT:'Use at least 10 characters.',PASSWORD_WEAK:'Include uppercase, lowercase and a number.',PASSWORD_REUSED:'Choose a different password.'};setError(m[err.code]||'Password could not be changed.')}finally{setBusy(false)}}
return h('div',{className:'login'},h('section',{className:'loginIntro'},h('div',{className:'logo'},'T'),h('small',null,'FIRST LOGIN SECURITY'),h('h1',null,'Set your private password.'),h('p',null,'Your temporary first-login password must be replaced before using the Business OS.')),
h('form',{className:'loginCard',onSubmit:submit},h('div',{className:'logo mini'},'T'),h('h2',null,'Password change required'),h('p',null,user.name+' · '+user.email),error?h('div',{className:'authError'},error):null,h(Field,{label:'Temporary password',value:current,onChange:setCurrent,type:'password'}),h(Field,{label:'New password',value:next,onChange:setNext,type:'password'}),h(Field,{label:'Confirm new password',value:confirm,onChange:setConfirm,type:'password'}),h('button',{className:'primary',disabled:busy||!current||!next||!confirm},busy?'Updating…':'Set new password →'),h('button',{type:'button',className:'textButton',onClick:onLogout},'Sign out')))}
function Loading(){return h('div',{className:'authLoading'},h('div',{className:'logo'},'T'),h('b',null,'Tea Shop BD Business OS'),h('span',null,'Checking secure session…'))}
function Shell(){const[user,setUser]=React.useState(undefined),[page,setPage]=React.useState('Dashboard'),[state,setState]=React.useState(load());
React.useEffect(()=>save(state),[state]);
React.useEffect(()=>{api('me').then(r=>setUser(r.user)).catch(()=>setUser(null))},[]);
async function logout(){try{await api('logout',{method:'POST',body:{}})}catch{}csrfToken='';setUser(null);setPage('Dashboard')}
if(user===undefined)return h(Loading);
if(!user)return h(Login,{onLogin:u=>{setUser(u);setPage(u.role==='CASHIER'?'POS / Sales':'Dashboard')}});
if(Number(user.must_change_password)===1)return h(ChangePassword,{user,onDone:u=>setUser(u),onLogout:logout});
const allowed=access[user.role]||['Dashboard'];if(!allowed.includes(page))setTimeout(()=>setPage(allowed[0]),0);
const groups=[
 ['Command Center',['Dashboard','Tea']],
 ['Supply & Production',['Purchase & Suppliers','Blending & Production','QC & Wastage','Packaging','Products / SKU','Pricing Engine']],
 ['Stock & Distribution',['Inventory / Warehouse','Outlets / Franchise','Franchise & Retail Operations','Logistics']],
 ['Retail',['POS / Sales','Customers','Corporate / B2B']],
 ['Finance & Control',['Margin & Settlement','Finance & Accounts','Profit & Loss','Performance & Incentives','Reports']],
 ['Governance',['Approvals','Documents','Notifications','Users & Roles','Audit Log','Settings']]
];
const initials=(user.role_name||user.role||'TS').split(' ').map(x=>x[0]).slice(0,2).join('');
return h('div',{className:'app'},
 h('aside',null,
  h('div',{className:'brand'},h('div',{className:'logo small'},'T'),h('div',null,h('b',null,'Tea Shop BD'),h('span',null,'Business Workspace'))),
  h('div',{className:'rolebox'},h('div',{className:'roleAvatar'},initials),h('div',{className:'roleMeta'},h('b',null,user.role_name||user.role),h('span',null,user.email||'Secure account')),h('i',{className:'onlineDot'})),
  h('nav',null,groups.map(([label,items])=>{const visible=items.filter(x=>allowed.includes(x));if(!visible.length)return null;return h('div',{className:'navGroup',key:label},h('small',null,label),visible.map(n=>h('button',{key:n,className:n===page?'active':'',onClick:()=>setPage(n)},h('span',{className:'navMark'}),h('span',null,n))))})),
  h('button',{className:'logout',onClick:logout},'Sign out')
 ),
 h('main',null,
  h('header',null,
   h('div',{className:'topCrumb'},h('span',null,'Tea Shop BD'),h('i',null,'/'),h('b',null,page)),
   h('div',{className:'headerRight'},h(Pill,{kind:'success'},'LIVE'),h('span',{className:'topRole'},user.role_name||user.role),h('div',{className:'avatar'},initials))
  ),
  h('div',{className:'pageCanvas'},h(Page,{page,user,state,setState}))
 ))}
function Page({page,user,state,setState}){const p={
 'Dashboard':user?.role==='OPERATIONS'?OperationsDashboard:Overview,
 'Tea':TeaHub,
 'Purchase & Suppliers':PurchaseHub,
 'Blending & Production':Production,
 'QC & Wastage':QCWastage,
 'Packaging':Packaging,
 'Products / SKU':ProductsSKU,
 'Pricing Engine':Pricing,
 'Inventory / Warehouse':Inventory,
 'Outlets / Franchise':Franchises,
 'Franchise & Retail Operations':OperationsWorkspace,
 'POS / Sales':POS,
 'Margin & Settlement':Settlements,
 'Finance & Accounts':Finance,
 'Profit & Loss':Finance,
 'Performance & Incentives':()=>h(RecordsWorkspace,{module:'performance',title:'Performance & Incentives',desc:'Role-based scorecards and performance share on distributable Franchise Division profit.'}),
 'Customers':()=>h(RecordsWorkspace,{module:'customers',title:'Customers',desc:'Outlet customer and loyalty records linked to retail sales.'}),
 'Corporate / B2B':()=>h(RecordsWorkspace,{module:'b2b',title:'Corporate / B2B',desc:'Head-office corporate tea leads and bulk orders, separate from franchise economics.'}),
 'Logistics':()=>h(RecordsWorkspace,{module:'logistics',title:'Logistics',desc:'Dispatch, challan, carrier, delivery cost and outlet receiving status.'}),
 'Reports':Reports,
 'Approvals':()=>h(RecordsWorkspace,{module:'approvals',title:'Approvals',desc:'Sensitive price, stock, expense, settlement and operational approval queue.'}),
 'Documents':()=>h(RecordsWorkspace,{module:'documents',title:'Documents',desc:'Purchase, production, stock, franchise, settlement and POS document registry.'}),
 'Notifications':()=>h(RecordsWorkspace,{module:'notifications',title:'Notifications',desc:'Low stock, high wastage, overdue settlement, QC failure and network alerts.'}),
 'Users & Roles':Users,
 'Audit Log':()=>h(RecordsWorkspace,{module:'audit',title:'Audit Log',desc:'Who changed what, when and from where.'}),
 'Settings':SettingsWorkspace
}[page]||Overview;return h(p,{user,state,setState})}
function OperationsDashboard(){
 const[data,setData]=React.useState(null),[error,setError]=React.useState('');
 React.useEffect(()=>{api('operations.dashboard').then(setData).catch(e=>setError('Operations command center could not be loaded: '+(e.code||'ERROR')))},[]);
 if(!data)return error?h('div',{className:'authError'},error):h(Loading);
 const n=data.network||{},s=data.sales||{},st=data.settlement||{},perf=data.performance,w=data.work||{};
 const growth=s.growth_percent===null||s.growth_percent===undefined?'New baseline':((Number(s.growth_percent)>=0?'+':'')+Number(s.growth_percent).toFixed(1)+'% vs previous month');
 return h(React.Fragment,null,
  h('section',{className:'hero opsHero'},
   h('div',null,h('small',null,'FRANCHISE & RETAIL OPERATIONS'),h('h1',null,'Network command center.'),h('p',null,'Outlet sales, stock discipline, settlement follow-up, pipeline and performance—without manufacturing or Owner-only controls.')),
   h('div',{className:'goal'},h('span',null,'NETWORK TARGET'),h('b',null,String(n.active_outlets||0)+' ',h('em',null,'/ 150')),h('small',null,'active outlets'))
  ),
  h('div',{className:'stats'},
   h(Card,{t:'Network sales · this month',v:money(s.current||0),s:growth}),
   h(Card,{t:'Active outlets',v:String(n.active_outlets||0),s:String(n.pipeline_outlets||0)+' pipeline / setup'}),
   h(Card,{t:'Needs attention',v:String(n.attention_outlets||0),s:String((data.stock_gaps||[]).length)+' active outlets with no stock value'}),
   h(Card,{t:'Settlement follow-up',v:money(st.open_amount||0),s:String(st.open_count||0)+' open settlement rows'})
  ),
  h('div',{className:'stats opsSecondary'},
   h(Card,{t:'POS receipts · month',v:String(s.receipts||0),s:'Verified sales source'}),
   h(Card,{t:'Franchise margin earned',v:money(s.earned_margin||0),s:'25 / 27 / 30 / approved custom'}),
   h(Card,{t:'Outlet pipeline',v:String(n.pipeline_outlets||0),s:'Pipeline + setup'}),
   h(Card,{t:'Performance',v:perf?Number(perf.total_score||0).toFixed(1):'—',s:perf?(String(perf.status||'draft').toUpperCase()+' · '+perf.period_start+' → '+perf.period_end):'Awaiting approved scorecard'})
  ),
  h('div',{className:'stats opsWorkStats'},
   h(Card,{t:'Open tasks',v:String(w.open_tasks||0),s:String(w.overdue_tasks||0)+' overdue'}),
   h(Card,{t:'Support tickets',v:String(w.open_tickets||0),s:String(w.overdue_tickets||0)+' overdue'}),
   h(Card,{t:'Field visits',v:String(w.visits_next_7d||0),s:'scheduled in next 7 days'}),
   h(Card,{t:'Compliance & training',v:String(w.open_compliance||0),s:String(w.training_attention||0)+' training attention'})
  ),
  h('div',{className:'twocol'},
   h('section',{className:'panel'},h(Title,{t:'Low-performing active outlets',tag:'30-DAY SALES'}),h(DataTable,{rows:data.low_performers||[],cols:[['code','Code'],['name','Outlet'],['district','District'],['sales_30d','30d sales',money],['receipts_30d','Receipts'],['stock_value','Stock value',money],['health','Health']],empty:'No active outlet performance data yet.'})),
   h('section',{className:'panel'},h(Title,{t:'Settlement follow-up',tag:'OPEN'}),h(DataTable,{rows:data.settlements_due||[],cols:[['outlet','Outlet'],['period_end','Period end'],['verified_sales','Verified sales',money],['earned_margin','Margin',money],['net_payable','Net payable',money],['status','Status']],empty:'No open settlements.'}))
  ),
  h('div',{className:'twocol'},
   h('section',{className:'panel'},h(Title,{t:'Stock gaps',tag:'OUTLET ACTION'}),h(DataTable,{rows:data.stock_gaps||[],cols:[['code','Code'],['name','Outlet'],['district','District'],['stock_value','Stock value',money],['last_sale_at','Last sale']],empty:'No active outlet currently has a zero stock value.'})),
   h('section',{className:'panel'},h(Title,{t:'Role performance',tag:'P&L-BASED'}),perf?
    h('div',{className:'opsScore'},
     h('div',null,h('span',null,'Score'),h('b',null,Number(perf.total_score||0).toFixed(1))),
     h('div',null,h('span',null,'Share rate'),h('b',null,Number(perf.performance_share_percent||0).toFixed(2)+'%')),
     h('div',null,h('span',null,'Share amount'),h('b',null,money(perf.performance_share_amount||0))),
     h('div',null,h('span',null,'Status'),h('b',null,String(perf.status||'draft').toUpperCase()))
    ):h(Empty,{text:'No performance period has been approved yet.'})
   )
  ),
  h('section',{className:'panel roleScope'},
   h(Title,{t:'Authority boundary',tag:'ROLE CONTROL'}),
   h('div',{className:'scopeGrid'},
    h('div',null,h('b',null,'Operational control'),h('p',null,'Outlet pipeline, launch, sales monitoring, stock follow-up, settlement follow-up, territory, customers, logistics and network reports.')),
    h('div',null,h('b',null,'Owner-controlled'),h('p',null,'Tea sourcing, blend formula authority, production/QC approval, pricing policy, margin override, company finance, users, audit and system settings.'))
   )
  )
 );
}
function Overview(){const[data,setData]=React.useState(null),[error,setError]=React.useState('');
React.useEffect(()=>{api('dashboard').then(setData).catch(()=>setError('Live dashboard could not be loaded.'))},[]);
const sales=data?.verified_sales||0,margin=data?.franchise_earned_margin||0,company=data?.company_contribution||0,outlets=data?.active_outlets||0,receipts=data?.receipts||0;
return h(React.Fragment,null,
h('section',{className:'hero'},h('div',null,h('small',null,'NETWORK COMMAND CENTER'),h('h1',null,'Founder-controlled growth.'),h('p',null,'Verified sales, accountable stock, outlet health and unit economics in one operating system.')),h('div',{className:'goal'},h('span',null,'NETWORK TARGET'),h('b',null,outlets+' ',h('em',null,'/ 150')),h('small',null,'active outlets'))),
error?h('div',{className:'authError'},error):null,
h('div',{className:'stats'},h(Card,{t:'Verified POS sales',v:money(sales),s:receipts+' receipts'}),h(Card,{t:'Franchise earned margin',v:money(margin),s:'Only on verified eligible sales'}),h(Card,{t:'Company contribution',v:money(company),s:'After franchise margin & expenses'}),h(Card,{t:'Active outlets',v:String(outlets),s:'Live database'})),
h('div',{className:'twocol'},h('section',{className:'panel'},h(Title,{t:'Franchise margin policy',tag:'25 / 27 / 30 / MANUAL'}),h('div',{className:'tiers'},Object.entries(D.marginTiers).map(([k,v])=>h('div',{key:k},h('b',null,v+'%'),h('span',null,k))))),h('section',{className:'panel'},h(Title,{t:'Franchise & Retail Operations performance share',tag:'P&L ONLY'}),h('div',{className:'tiers'},Object.entries(D.performanceShareTiers).map(([k,v])=>h('div',{key:k},h('b',null,v+'%'),h('span',null,k)))),h('p',{className:'muted'},'Calculated only on positive distributable Franchise Division profit.'))),
h('section',{className:'panel'},h(Title,{t:'A–Z operating chain',tag:'SYSTEM'}),h('div',{className:'flow'},'Purchase → Production/QC → Packaging/Rebuild → Inventory → Franchise → POS Verified Sale → Settlement → Finance/P&L → Management Share → Tea Shop BD Net')))}
function Card({t,v,s}){return h('article',{className:'card'},h('span',null,t),h('b',null,v),h('small',null,s))}
function Title({t,tag}){return h('div',{className:'title'},h('h3',null,t),h(Pill,null,tag))}
function Pricing({user}){const[settings,setSettings]=React.useState(null),[products,setProducts]=React.useState([]),[packCosts,setPackCosts]=React.useState([]),[cat,setCat]=React.useState('All'),[margin,setMargin]=React.useState(30),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState('');
React.useEffect(()=>{Promise.all([api('settings'),api('products'),api('pricing.packaging-costs')]).then(([a,b,d])=>{setSettings(a.settings);setProducts((b.products||[]).map(p=>({...p,costKg:Number(p.effective_cost_per_kg||p.purchase_cost_per_kg),baseCostKg:Number(p.purchase_cost_per_kg)})));setPackCosts(d.costs||[])}).catch(()=>setMsg('Pricing data could not be loaded.'))},[]);
if(!settings)return h(Loading);
const assumptions={tube30:Number(settings.pricing_tube30||60),pouch50:Number(settings.pricing_pouch50||18),pouch100:Number(settings.pricing_pouch100||22),ctc250:Number(settings.pricing_ctc250||24),ctc500:Number(settings.pricing_ctc500||28),labour:Number(settings.pricing_labour||5),overhead:Number(settings.pricing_overhead||7),logistics:Number(settings.pricing_logistics||4),wastagePct:Number(settings.pricing_wastage_percent||3)};
const packs=p=>p.category==='CTC / Black Tea'?[250,500,1000,2000,5000]:[30,50,100,200,250];
const list=products.filter(p=>cat==='All'||p.category===cat);
const packCostMap=Object.fromEntries(packCosts.map(x=>[String(x.product_id)+'-'+String(x.grams),Number(x.packaging_cost||0)]));
function priceCalc(p,g){const actual=Number(packCostMap[String(p.id)+'-'+String(g)]||0);if(actual<=0)return economics(p,g,assumptions,margin);const raw=p.costKg*g/1000,waste=raw*(assumptions.wastagePct/100),landed=raw+actual+assumptions.labour+assumptions.overhead+assumptions.logistics+waste,min=round10(landed/(1-(margin/100)-.15));return{raw,pack:actual,waste,landed,min,fr:min*margin/100,company:min*(1-margin/100)}}
function setNum(k,v){setSettings({...settings,[k]:v})}
async function save(){if(user.role!=='OWNER')return;setBusy(true);setMsg('');try{await api('settings.save',{method:'POST',body:{settings:{pricing_tube30:settings.pricing_tube30,pricing_pouch50:settings.pricing_pouch50,pricing_pouch100:settings.pricing_pouch100,pricing_ctc250:settings.pricing_ctc250,pricing_ctc500:settings.pricing_ctc500,pricing_labour:settings.pricing_labour,pricing_overhead:settings.pricing_overhead,pricing_logistics:settings.pricing_logistics,pricing_wastage_percent:settings.pricing_wastage_percent}}});setMsg('Pricing assumptions saved to the live database.')}catch(e){setMsg('Could not save pricing assumptions: '+(e.code||'ERROR'))}finally{setBusy(false)}}
return h(React.Fragment,null,
h('section',{className:'moduleHead'},h('small',null,'A–Z PRICING ENGINE'),h('h1',null,'Tea costing & sustainable MRP'),h('p',null,'Latest QC-passed blend cost (fallback: master cost) + packaging + labour + wastage + overhead + logistics + franchise earning.')),
msg?h('div',{className:'notice'},msg):null,
h('section',{className:'panel controls'},h('div',{className:'controlGrid'},[['pricing_tube30','30g Tube'],['pricing_pouch50','50g Pouch'],['pricing_pouch100','100g Pouch'],['pricing_ctc250','CTC 250g pack'],['pricing_ctc500','CTC 500g pack'],['pricing_labour','Labour'],['pricing_overhead','Overhead'],['pricing_logistics','Logistics'],['pricing_wastage_percent','Wastage %']].map(([k,l])=>h(Field,{key:k,label:l,value:settings[k]??'',type:'number',step:'.01',onChange:v=>setNum(k,v)}))),user.role==='OWNER'?h('button',{className:'primary fit',disabled:busy,onClick:save},busy?'Saving…':'Save live assumptions'):null),
h('div',{className:'toolbar'},h('select',{value:cat,onChange:e=>setCat(e.target.value)},h('option',null,'All'),[...new Set(products.map(p=>p.category))].map(x=>h('option',{key:x},x))),h('label',null,'Franchise % ',h('input',{className:'short',type:'number',value:margin,onChange:e=>setMargin(Number(e.target.value))})),h(Pill,{kind:'success'},products.length+' live SKUs'),h(Pill,{kind:''},'Actual blend + BOM cost when available')),
h('div',{className:'tablewrap'},h('table',null,h('thead',null,h('tr',null,['SKU','Category','৳/kg','Pack','Raw','Packaging','Landed','Planning MRP','Franchise','Company'].map(x=>h('th',{key:x},x)))),h('tbody',null,list.flatMap(p=>packs(p).map(g=>{const x=priceCalc(p,g);return h('tr',{key:p.id+'-'+g},h('td',null,h('b',null,p.name)),h('td',null,p.category),h('td',null,money(p.costKg)),h('td',null,g+'g'),h('td',null,money(x.raw)),h('td',null,money(x.pack)),h('td',null,money(x.landed)),h('td',null,h('b',null,money(x.min))),h('td',null,money(x.fr)),h('td',null,money(x.company)))}))))))}
function Suppliers(){const[rows,setRows]=React.useState([]),[report,setReport]=React.useState([]),[ledger,setLedger]=React.useState([]),[ledgerMeta,setLedgerMeta]=React.useState({}),[type,setType]=React.useState('tea'),[name,setName]=React.useState(''),[contact,setContact]=React.useState(''),[phone,setPhone]=React.useState(''),[email,setEmail]=React.useState(''),[address,setAddress]=React.useState(''),[terms,setTerms]=React.useState(0),[credit,setCredit]=React.useState(0),[opening,setOpening]=React.useState(0),[sid,setSid]=React.useState(''),[amount,setAmount]=React.useState(''),[method,setMethod]=React.useState('bank'),[ref,setRef]=React.useState(''),[returnAmount,setReturnAmount]=React.useState(''),[returnType,setReturnType]=React.useState('tea'),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState('');
async function loadAll(){try{const[a,b]=await Promise.all([api('suppliers'),api('supplier.report')]);setRows(a.suppliers||[]);setReport(b.report||[]);if(!sid&&a.suppliers?.[0])setSid(String(a.suppliers[0].id))}catch{setMsg('Supplier accounts could not be loaded.')}}
async function loadLedger(id){if(!id){setLedger([]);return}try{const r=await api('supplier.ledger?supplier_id='+encodeURIComponent(id));setLedger(r.ledger||[]);setLedgerMeta(r)}catch{setLedger([])}}
React.useEffect(()=>{loadAll()},[]);React.useEffect(()=>{loadLedger(sid)},[sid]);
async function addSupplier(){if(!name)return;setBusy(true);setMsg('');try{await api('supplier.create',{method:'POST',body:{supplier_type:type,name,contact_person:contact,phone,email,address,payment_terms_days:Number(terms||0),credit_limit:Number(credit||0),opening_balance:Number(opening||0)}});setName('');setContact('');setPhone('');setEmail('');setAddress('');setOpening(0);setMsg('Supplier added.');await loadAll()}catch(e){setMsg('Supplier create failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
async function paySupplier(){if(!sid||Number(amount)<=0)return;setBusy(true);setMsg('');try{await api('supplier.payment.create',{method:'POST',body:{supplier_id:Number(sid),amount:Number(amount),payment_method:method,reference_no:ref}});setAmount('');setRef('');setMsg('Supplier payment recorded.');await loadAll();await loadLedger(sid)}catch(e){setMsg('Payment failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
async function addReturn(){if(!sid||Number(returnAmount)<=0)return;setBusy(true);setMsg('');try{await api('supplier.return.create',{method:'POST',body:{supplier_id:Number(sid),amount:Number(returnAmount),source_type:returnType,reference_no:ref}});setReturnAmount('');setMsg('Supplier return / credit recorded.');await loadAll();await loadLedger(sid)}catch(e){setMsg('Return failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
const totalPurchases=rows.reduce((a,b)=>a+Number(b.purchases||0),0),totalPaid=rows.reduce((a,b)=>a+Number(b.payments||0),0),totalDue=rows.reduce((a,b)=>a+Number(b.balance||0),0);
return h(React.Fragment,null,
h('section',{className:'moduleHead'},h('small',null,'SUPPLIER & PROCUREMENT CONTROL'),h('h1',null,'All suppliers, ledger & payable'),h('p',null,'Tea, packaging, logistics and other suppliers with purchases, payments, returns, outstanding and aging.')),
msg?h('div',{className:'notice'},msg):null,
h('div',{className:'stats'},h(Card,{t:'Total supplier purchase',v:money(totalPurchases),s:rows.length+' suppliers'}),h(Card,{t:'Supplier payments',v:money(totalPaid),s:'Recorded payments'}),h(Card,{t:'Outstanding payable',v:money(totalDue),s:'Opening + purchase − payment − return'}),h(Card,{t:'90+ day payable',v:money(report.reduce((a,b)=>a+Number(b.d90_plus||0),0)),s:'Aging watch'})),
h('div',{className:'twocol'},
h('section',{className:'panel'},h(Title,{t:'Supplier master',tag:'ALL TYPES'}),h('div',{className:'formrow'},h('label',{className:'field'},'Type',h('select',{value:type,onChange:e=>setType(e.target.value)},['tea','packaging','logistics','other'].map(x=>h('option',{key:x,value:x},x)))),h(Field,{label:'Supplier name',value:name,onChange:setName}),h(Field,{label:'Contact person',value:contact,onChange:setContact}),h(Field,{label:'Phone',value:phone,onChange:setPhone}),h(Field,{label:'Email',value:email,onChange:setEmail,type:'email'}),h(Field,{label:'Address',value:address,onChange:setAddress}),h(Field,{label:'Payment terms days',value:terms,onChange:setTerms,type:'number'}),h(Field,{label:'Credit limit',value:credit,onChange:setCredit,type:'number'}),h(Field,{label:'Opening balance',value:opening,onChange:setOpening,type:'number'}),h('button',{className:'primary fit',disabled:busy,onClick:addSupplier},'Add supplier'))),
h('section',{className:'panel'},h(Title,{t:'Payment / return',tag:'PAYABLE'}),h('div',{className:'formrow'},h('label',{className:'field'},'Supplier',h('select',{value:sid,onChange:e=>setSid(e.target.value)},rows.map(x=>h('option',{key:x.id,value:x.id},x.name+' · '+x.supplier_type)))),h(Field,{label:'Payment amount',value:amount,onChange:setAmount,type:'number'}),h('label',{className:'field'},'Method',h('select',{value:method,onChange:e=>setMethod(e.target.value)},['bank','cash','mobile banking','cheque'].map(x=>h('option',{key:x},x)))),h(Field,{label:'Reference',value:ref,onChange:setRef}),h('button',{className:'primary fit',disabled:busy,onClick:paySupplier},'Record payment')),h('div',{className:'formrow'},h('label',{className:'field'},'Return type',h('select',{value:returnType,onChange:e=>setReturnType(e.target.value)},['tea','packaging','other'].map(x=>h('option',{key:x},x)))),h(Field,{label:'Return / credit amount',value:returnAmount,onChange:setReturnAmount,type:'number'}),h('button',{className:'miniBtn',disabled:busy,onClick:addReturn},'Record return / credit')))),
h(DataTable,{rows,cols:[['supplier_type','Type'],['name','Supplier'],['contact_person','Contact'],['purchases','Purchases',money],['payments','Paid',money],['returns','Returns',money],['balance','Outstanding',money]],empty:'No suppliers added yet.'}),
h('div',{className:'twocol supplierDetail'},h('section',{className:'panel'},h(Title,{t:'Selected supplier ledger',tag:'LEDGER'}),sid?h(DataTable,{rows:ledger,cols:[['entry_date','Date'],['entry_type','Entry'],['reference_no','Reference'],['debit','Debit',money],['credit','Credit',money],['balance','Running balance',money]],empty:'No ledger entries yet.'}):h(Empty,{text:'Select a supplier.'}),h('div',{className:'ledgerBalance'},h('span',null,'Opening ',money(ledgerMeta.opening_balance||0)),h('b',null,'Closing ',money(ledgerMeta.closing_balance||0)))),h('section',{className:'panel'},h(Title,{t:'Supplier aging report',tag:'FIFO AGING'}),h(DataTable,{rows:report,cols:[['name','Supplier'],['supplier_type','Type'],['balance','Outstanding',money],['current','Current',money],['d0_30','0–30',money],['d31_60','31–60',money],['d61_90','61–90',money],['d90_plus','90+',money]],empty:'No payable aging yet.'}))))}
function Purchases(){const[suppliers,setSuppliers]=React.useState([]),[rawtea,setRawtea]=React.useState([]),[receipts,setReceipts]=React.useState([]),[sid,setSid]=React.useState(''),[rid,setRid]=React.useState(''),[kg,setKg]=React.useState(''),[rate,setRate]=React.useState(''),[lot,setLot]=React.useState(''),[invoice,setInvoice]=React.useState(''),[challan,setChallan]=React.useState(''),[due,setDue]=React.useState(''),[code,setCode]=React.useState(''),[rawName,setRawName]=React.useState(''),[teaType,setTeaType]=React.useState('CTC'),[origin,setOrigin]=React.useState(''),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState('');
async function loadLive(){try{const[a,b,d]=await Promise.all([api('suppliers?type=tea'),api('rawtea'),api('purchases')]);setSuppliers(a.suppliers||[]);setRawtea(b.rawtea||[]);setReceipts(d.purchases||[]);if(!sid&&a.suppliers?.[0])setSid(String(a.suppliers[0].id));if(!rid&&b.rawtea?.[0])setRid(String(b.rawtea[0].id))}catch{setMsg('Raw tea purchase data could not be loaded.')}}
React.useEffect(()=>{loadLive()},[]);
async function addRaw(){if(!rawName)return;setBusy(true);setMsg('');try{await api('rawtea.create',{method:'POST',body:{code,name:rawName,tea_type:teaType,origin}});setCode('');setRawName('');setOrigin('');await loadLive()}catch(e){setMsg('Raw tea master create failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
async function purchase(){if(!sid||!rid||Number(kg)<=0)return;setBusy(true);setMsg('');try{const r=await api('rawtea.purchase.create',{method:'POST',body:{supplier_id:Number(sid),raw_tea_material_id:Number(rid),kg:Number(kg),rate:Number(rate||0),batch_no:lot,invoice_no:invoice,challan_no:challan,due_date:due||undefined}});setKg('');setRate('');setLot('');setInvoice('');setChallan('');setDue('');setMsg('GRN '+r.reference_no+' saved · '+Number(kg||0)+' kg received.');await loadLive()}catch(e){setMsg('Purchase failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
return h(React.Fragment,null,
h('section',{className:'moduleHead'},h('small',null,'RAW TEA PROCUREMENT'),h('h1',null,'Purchase raw tea by kilogram'),h('p',null,'Supplier-wise kg purchase → GRN → raw tea stock → blend consumption, with invoice and payable due date.')),
msg?h('div',{className:'notice'},msg):null,
h('div',{className:'twocol'},
h('section',{className:'panel'},h(Title,{t:'Raw tea material master',tag:'RAW STOCK'}),h('div',{className:'formrow'},h(Field,{label:'Code (optional)',value:code,onChange:setCode}),h(Field,{label:'Raw tea name / grade',value:rawName,onChange:setRawName}),h(Field,{label:'Tea type',value:teaType,onChange:setTeaType}),h(Field,{label:'Garden / origin',value:origin,onChange:setOrigin}),h('button',{className:'primary fit',disabled:busy,onClick:addRaw},'Add raw tea'))),
h('section',{className:'panel'},h(Title,{t:'Receive tea from supplier',tag:'KG + RATE + DUE'}),suppliers.length?h('div',{className:'formrow'},h('label',{className:'field'},'Tea supplier',h('select',{value:sid,onChange:e=>setSid(e.target.value)},suppliers.map(x=>h('option',{key:x.id,value:x.id},x.name+' · due '+money(x.balance))))),h('label',{className:'field'},'Raw tea',h('select',{value:rid,onChange:e=>setRid(e.target.value)},rawtea.map(x=>h('option',{key:x.id,value:x.id},x.name+' · stock '+Number(x.stock_kg||0).toFixed(2)+'kg')))),h(Field,{label:'Quantity kg',value:kg,onChange:setKg,type:'number'}),h(Field,{label:'Rate / kg',value:rate,onChange:setRate,type:'number'}),h(Field,{label:'Lot / batch',value:lot,onChange:setLot}),h(Field,{label:'Invoice no',value:invoice,onChange:setInvoice}),h(Field,{label:'Challan no',value:challan,onChange:setChallan}),h(Field,{label:'Due date (optional)',value:due,onChange:setDue,type:'date'}),h('button',{className:'primary fit',disabled:busy||!rawtea.length,onClick:purchase},'Receive GRN')):h(Empty,{text:'Add a Tea supplier from Suppliers first.'}))),
h('div',{className:'stats'},h(Card,{t:'Raw tea stock lines',v:String(rawtea.length),s:'Material / grade master'}),h(Card,{t:'Raw tea on hand',v:rawtea.reduce((a,b)=>a+Number(b.stock_kg||0),0).toFixed(2)+' kg',s:'After blend consumption'}),h(Card,{t:'Tea supplier due',v:money(suppliers.reduce((a,b)=>a+Number(b.balance||0),0)),s:'Supplier ledger'}),h(Card,{t:'GRN entries',v:String(receipts.length),s:'Recent purchase history'})),
h(DataTable,{rows:rawtea,cols:[['code','Code'],['name','Raw tea'],['tea_type','Type'],['origin','Origin'],['stock_kg','Raw stock kg'],['avg_rate','Avg rate/kg',money]],empty:'No raw tea material configured yet.'}),
h('section',{className:'moduleHead compactHead'},h('small',null,'RECENT GRN'),h('h1',null,'Raw tea purchase history')),
h(DataTable,{rows:receipts,cols:[['date','Date'],['reference_no','GRN'],['supplier','Supplier'],['product','Raw tea'],['kg','Kg'],['rate','Rate/kg',money],['batch_no','Lot']],empty:'No raw tea purchases yet.'}))}
function Production(){const[rows,setRows]=React.useState([]),[rawtea,setRawtea]=React.useState([]),[products,setProducts]=React.useState([]),[pid,setPid]=React.useState(''),[rid,setRid]=React.useState(''),[componentKg,setComponentKg]=React.useState(''),[components,setComponents]=React.useState([]),[output,setOutput]=React.useState(''),[batch,setBatch]=React.useState(''),[qc,setQc]=React.useState('pass'),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState('');
async function loadLive(){try{const[a,b,d]=await Promise.all([api('blends'),api('rawtea'),api('products')]);setRows(a.blends||[]);setRawtea(b.rawtea||[]);setProducts(d.products||[]);if(!rid&&b.rawtea?.[0])setRid(String(b.rawtea[0].id));if(!pid&&d.products?.[0])setPid(String(d.products[0].id))}catch{setMsg('Blend data could not be loaded.')}}
React.useEffect(()=>{loadLive()},[]);
function addComponent(){const r=rawtea.find(x=>String(x.id)===String(rid)),kg=Number(componentKg);if(!r||kg<=0)return;const existing=components.find(x=>x.raw_tea_material_id===Number(rid));setComponents(existing?components.map(x=>x.raw_tea_material_id===Number(rid)?{...x,kg:x.kg+kg}:x):[...components,{raw_tea_material_id:Number(rid),name:r.name,kg,avg_rate:Number(r.avg_rate||0)}]);setComponentKg('')}
function removeComponent(id){setComponents(components.filter(x=>x.raw_tea_material_id!==id))}
const inputKg=components.reduce((a,b)=>a+Number(b.kg),0),inputCost=components.reduce((a,b)=>a+Number(b.kg)*Number(b.avg_rate||0),0),projectedCostKg=Number(output)>0?inputCost/Number(output):0;
async function saveBlend(){if(!pid||!components.length||Number(output)<0)return;setBusy(true);setMsg('');try{const r=await api('blend.create',{method:'POST',body:{product_id:Number(pid),batch_no:batch,output_kg:Number(output),qc_status:qc,components:components.map(x=>({raw_tea_material_id:x.raw_tea_material_id,kg:x.kg}))}});setComponents([]);setOutput('');setBatch('');setMsg('Blend '+r.batch_no+' saved · input '+Number(r.input_kg).toFixed(2)+'kg · output '+Number(r.output_kg).toFixed(2)+'kg.');await loadLive()}catch(e){setMsg(e.code==='INSUFFICIENT_RAW_TEA'?'Not enough raw tea stock for one of the blend components.':'Blend failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
return h(React.Fragment,null,
h('section',{className:'moduleHead'},h('small',null,'BLEND PRODUCTION'),h('h1',null,'Blending & Production'),h('p',null,'Mix multiple purchased raw teas by kg into one finished Tea Shop BD blend, with yield and wastage tracking.')),
msg?h('div',{className:'notice'},msg):null,
h('section',{className:'panel'},h(Title,{t:'Create blend batch',tag:'KG RECIPE'}),h('div',{className:'formrow'},h('label',{className:'field'},'Raw tea component',h('select',{value:rid,onChange:e=>setRid(e.target.value)},rawtea.map(x=>h('option',{key:x.id,value:x.id},x.name+' · '+Number(x.stock_kg||0).toFixed(2)+'kg available')))),h(Field,{label:'Component kg',value:componentKg,onChange:setComponentKg,type:'number'}),h('button',{className:'miniBtn',onClick:addComponent},'Add component')),components.length?h('div',{className:'blendChips'},components.map(x=>h('button',{key:x.raw_tea_material_id,onClick:()=>removeComponent(x.raw_tea_material_id)},x.name+' · '+x.kg+'kg ×'))):h(Empty,{text:'Add one or more raw tea components for this blend.'}),h('div',{className:'blendCostBar'},h('span',null,'Estimated raw input cost ',money(inputCost)),h('b',null,'Projected blend cost/kg ',money(projectedCostKg))),h('div',{className:'formrow blendFinal'},h('label',{className:'field'},'Finished product',h('select',{value:pid,onChange:e=>setPid(e.target.value)},products.map(x=>h('option',{key:x.id,value:x.id},x.name)))),h(Field,{label:'Total input kg',value:inputKg,onChange:()=>{},type:'number'}),h(Field,{label:'Output kg',value:output,onChange:setOutput,type:'number'}),h(Field,{label:'Batch no (optional)',value:batch,onChange:setBatch}),h('label',{className:'field'},'QC',h('select',{value:qc,onChange:e=>setQc(e.target.value)},['pending','pass','hold','reject'].map(x=>h('option',{key:x},x)))),h('button',{className:'primary fit',disabled:busy||!components.length,onClick:saveBlend},busy?'Saving…':'Save blend'))),
h(DataTable,{rows,cols:[['batch_no','Batch'],['product','Finished blend'],['components','Composition'],['input','Input kg'],['output','Output kg'],['waste','Wastage kg'],['input_cost','Raw input cost',money],['cost_per_output_kg','Cost/kg',money],['qc_status','QC'],['produced_at','Produced']],empty:'No blend batches yet.'}))}
function Packaging(){const[rows,setRows]=React.useState([]),[products,setProducts]=React.useState([]),[suppliers,setSuppliers]=React.useState([]),[materials,setMaterials]=React.useState([]),[report,setReport]=React.useState({}),[pid,setPid]=React.useState(''),[grams,setGrams]=React.useState(500),[qty,setQty]=React.useState(''),[mrp,setMrp]=React.useState(''),[batch,setBatch]=React.useState(''),[mcode,setMcode]=React.useState(''),[mname,setMname]=React.useState(''),[munit,setMunit]=React.useState('pcs'),[mreorder,setMreorder]=React.useState(''),[psid,setPsid]=React.useState(''),[pmid,setPmid]=React.useState(''),[pqty,setPqty]=React.useState(''),[prate,setPrate]=React.useState(''),[pinvoice,setPinvoice]=React.useState(''),[pchallan,setPchallan]=React.useState(''),[pdue,setPdue]=React.useState(''),[plot,setPlot]=React.useState(''),[bomMid,setBomMid]=React.useState(''),[bomPer,setBomPer]=React.useState('1'),[bom,setBom]=React.useState([]),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState('');
async function loadLive(){try{const[a,b,d,e,r]=await Promise.all([api('packaging'),api('products'),api('suppliers?type=packaging'),api('packaging.materials'),api('packaging.report')]);setRows(a.packaging||[]);setProducts(b.products||[]);setSuppliers(d.suppliers||[]);setMaterials(e.materials||[]);setReport(r||{});if(!pid&&b.products?.[0]){setPid(String(b.products[0].id));setGrams(b.products[0].category==='CTC / Black Tea'?500:100)}if(!psid&&d.suppliers?.[0])setPsid(String(d.suppliers[0].id));if(!pmid&&e.materials?.[0])setPmid(String(e.materials[0].id));if(!bomMid&&e.materials?.[0])setBomMid(String(e.materials[0].id))}catch{setMsg('Packaging data could not be loaded.')}}
React.useEffect(()=>{loadLive()},[]);
const product=products.find(p=>String(p.id)===String(pid));const allowed=product?.category==='CTC / Black Tea'?[250,500,1000,2000,5000]:[30,50,100,200,250];
async function loadBom(productId,packGrams){if(!productId||!packGrams){setBom([]);return}try{const r=await api('packaging.bom?product_id='+encodeURIComponent(productId)+'&grams='+encodeURIComponent(packGrams));setBom((r.bom||[]).map(x=>({packaging_material_id:Number(x.material_id),name:x.name,unit:x.unit,qty_per_pack:Number(x.qty_per_pack),unit_cost:Number(x.unit_cost||0)})))}catch{setBom([])}}
React.useEffect(()=>{loadBom(pid,grams)},[pid,grams]);
function chooseProduct(v){setPid(v);const p=products.find(x=>String(x.id)===String(v));setGrams(p?.category==='CTC / Black Tea'?500:100)}
async function addMaterial(){if(!mname)return;setBusy(true);setMsg('');try{await api('packaging.material.create',{method:'POST',body:{code:mcode,name:mname,unit:munit,reorder_level:Number(mreorder||0)}});setMcode('');setMname('');setMreorder('');setMsg('Packaging material added.');await loadLive()}catch(e){setMsg('Material create failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
async function purchaseMaterial(){if(!psid||!pmid||Number(pqty)<=0)return;setBusy(true);setMsg('');try{const r=await api('packaging.purchase.create',{method:'POST',body:{supplier_id:Number(psid),packaging_material_id:Number(pmid),qty:Number(pqty),rate:Number(prate||0),invoice_no:pinvoice,challan_no:pchallan,due_date:pdue||undefined,batch_no:plot}});setPqty('');setPrate('');setPinvoice('');setPchallan('');setPdue('');setPlot('');setMsg('Packaging GRN '+r.reference_no+' saved.');await loadLive()}catch(e){setMsg('Packaging purchase failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
function addBom(){const m=materials.find(x=>String(x.id)===String(bomMid)),per=Number(bomPer);if(!m||per<=0)return;const found=bom.find(x=>x.packaging_material_id===Number(bomMid));setBom(found?bom.map(x=>x.packaging_material_id===Number(bomMid)?{...x,qty_per_pack:per,unit_cost:Number(m.avg_rate||0)}:x):[...bom,{packaging_material_id:Number(bomMid),name:m.name,unit:m.unit,qty_per_pack:per,unit_cost:Number(m.avg_rate||0)}])}
function removeBom(id){setBom(bom.filter(x=>x.packaging_material_id!==id))}
const bomCost=bom.reduce((a,b)=>a+Number(b.qty_per_pack)*Number(b.unit_cost||0),0);
async function completePackaging(){if(!pid||Number(qty)<=0||Number(mrp)<=0||!bom.length)return;setBusy(true);setMsg('');try{await api('packaging.create',{method:'POST',body:{product_id:Number(pid),grams:Number(grams),qty:Number(qty),mrp:Number(mrp),batch_no:batch,labour_cost:0,sealing_cost:0,other_cost:0,bom:bom.map(x=>({packaging_material_id:x.packaging_material_id,qty_per_pack:Number(x.qty_per_pack)}))}});setQty('');setMrp('');setBatch('');setMsg('Packaging job completed and material stock consumed.');await loadLive();await loadBom(pid,grams)}catch(e){setMsg(e.code==='INSUFFICIENT_PACKAGING_MATERIAL'?'Insufficient packaging stock: '+(e.material||'material'):'Packaging job failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
const low=(materials||[]).filter(x=>Number(x.low_stock)===1).length;
return h(React.Fragment,null,
h('section',{className:'moduleHead'},h('small',null,'PACKAGING SUPPLY + FINISHED GOODS'),h('h1',null,'Packaging procurement, BOM & rebuild'),h('p',null,'Packaging suppliers → material stock → per-pack BOM → actual consumption → finished stock.')),
msg?h('div',{className:'notice'},msg):null,
h('div',{className:'stats'},h(Card,{t:'Packaging purchases',v:money(report.total_purchases||0),s:suppliers.length+' packaging suppliers'}),h(Card,{t:'Packaging stock value',v:money(report.stock_value||0),s:materials.length+' materials'}),h(Card,{t:'Consumed material value',v:money(report.consumed_value||0),s:'Packaging jobs'}),h(Card,{t:'Low-stock materials',v:String(low),s:'At / below reorder level'})),
h('div',{className:'twocol'},
h('section',{className:'panel'},h(Title,{t:'Packaging material master',tag:'STOCK ITEM'}),h('div',{className:'formrow'},h(Field,{label:'Code (optional)',value:mcode,onChange:setMcode}),h(Field,{label:'Material name',value:mname,onChange:setMname}),h('label',{className:'field'},'Unit',h('select',{value:munit,onChange:e=>setMunit(e.target.value)},['pcs','roll','kg','box','sheet','set'].map(x=>h('option',{key:x},x)))),h(Field,{label:'Reorder level',value:mreorder,onChange:setMreorder,type:'number'}),h('button',{className:'primary fit',disabled:busy,onClick:addMaterial},'Add material'))),
h('section',{className:'panel'},h(Title,{t:'Packaging material purchase',tag:'SUPPLIER GRN'}),suppliers.length&&materials.length?h('div',{className:'formrow'},h('label',{className:'field'},'Packaging supplier',h('select',{value:psid,onChange:e=>setPsid(e.target.value)},suppliers.map(x=>h('option',{key:x.id,value:x.id},x.name+' · due '+money(x.balance))))),h('label',{className:'field'},'Material',h('select',{value:pmid,onChange:e=>setPmid(e.target.value)},materials.map(x=>h('option',{key:x.id,value:x.id},x.name+' · '+x.stock_qty+' '+x.unit)))),h(Field,{label:'Qty',value:pqty,onChange:setPqty,type:'number'}),h(Field,{label:'Rate / unit',value:prate,onChange:setPrate,type:'number'}),h(Field,{label:'Invoice',value:pinvoice,onChange:setPinvoice}),h(Field,{label:'Challan',value:pchallan,onChange:setPchallan}),h(Field,{label:'Due date',value:pdue,onChange:setPdue,type:'date'}),h(Field,{label:'Lot / batch',value:plot,onChange:setPlot}),h('button',{className:'primary fit',disabled:busy,onClick:purchaseMaterial},'Receive packaging GRN')):h(Empty,{text:'Add a Packaging supplier and at least one packaging material first.'}))),
h(DataTable,{rows:materials,cols:[['code','Code'],['name','Material'],['unit','Unit'],['stock_qty','Stock'],['avg_rate','Avg rate',money],['stock_value','Stock value',money],['reorder_level','Reorder'],['low_stock','Low?',v=>Number(v)?'YES':'No']],empty:'No packaging materials yet.'}),
h('section',{className:'moduleHead compactHead'},h('small',null,'PACK BOM'),h('h1',null,'Per-pack material recipe')),
h('section',{className:'panel'},h('div',{className:'formrow'},h('label',{className:'field'},'Finished product',h('select',{value:pid,onChange:e=>chooseProduct(e.target.value)},products.map(p=>h('option',{key:p.id,value:p.id},p.name)))),h('label',{className:'field'},'Pack size',h('select',{value:grams,onChange:e=>setGrams(Number(e.target.value))},allowed.map(g=>h('option',{key:g,value:g},g+'g')))),h('label',{className:'field'},'Packaging material',h('select',{value:bomMid,onChange:e=>setBomMid(e.target.value)},materials.map(x=>h('option',{key:x.id,value:x.id},x.name+' · '+x.unit)))),h(Field,{label:'Qty per pack',value:bomPer,onChange:setBomPer,type:'number'}),h('button',{className:'miniBtn',onClick:addBom},'Add / update BOM')),bom.length?h('div',{className:'blendChips'},bom.map(x=>h('button',{key:x.packaging_material_id,onClick:()=>removeBom(x.packaging_material_id)},x.name+' · '+x.qty_per_pack+' '+x.unit+' · '+money(Number(x.qty_per_pack)*Number(x.unit_cost||0))+' ×'))):h(Empty,{text:'Build the packaging BOM for this pack.'}),h('div',{className:'bomTotal'},'Estimated packaging material cost / pack: ',h('b',null,money(bomCost)))),
h('section',{className:'panel formrow'},h(Field,{label:'Pack quantity',value:qty,onChange:setQty,type:'number'}),h(Field,{label:'MRP / pack',value:mrp,onChange:setMrp,type:'number'}),h(Field,{label:'Blend / batch lot',value:batch,onChange:setBatch}),h('button',{className:'primary fit',disabled:busy||!bom.length,onClick:completePackaging},busy?'Saving…':'Complete packaging & consume BOM')),
h(DataTable,{rows,cols:[['job_no','Job'],['product','Product'],['pack','Pack g'],['qty','Qty'],['material_cost','Packaging material cost',money],['batch_no','Batch'],['completed_at','Completed']],empty:'No packaging jobs yet.'}),
h('section',{className:'moduleHead compactHead'},h('small',null,'PACKAGING SUPPLIER REPORT'),h('h1',null,'Supplier purchase summary')),
h(DataTable,{rows:report.suppliers||[],cols:[['supplier','Supplier'],['receipts','GRNs'],['purchases','Packaging purchase',money]],empty:'No packaging supplier purchases yet.'}))}
function Inventory({user}){const[data,setData]=React.useState({stock:[]}),[franchises,setFranchises]=React.useState([]),[fid,setFid]=React.useState(''),[packId,setPackId]=React.useState(''),[qty,setQty]=React.useState(''),[busy,setBusy]=React.useState(false),[error,setError]=React.useState('');
async function loadLive(){try{const[a,b]=await Promise.all([api('inventory'),api('franchises')]);setData(a);setFranchises(b.franchises||[]);if(!packId&&a.stock?.[0])setPackId(String(a.stock[0].pack_id));if(!fid&&b.franchises?.[0])setFid(String(b.franchises[0].id))}catch{setError('Inventory could not be loaded.')}}
React.useEffect(()=>{loadLive()},[]);
const canTransfer=['OWNER','WAREHOUSE','OPERATIONS'].includes(user.role);
async function transfer(){if(!fid||!packId||Number(qty)<=0)return;setBusy(true);setError('');try{await api('inventory.transfer.create',{method:'POST',body:{franchise_id:Number(fid),product_pack_id:Number(packId),qty:Number(qty)}});setQty('');await loadLive()}catch(e){setError(e.code==='INSUFFICIENT_CENTRAL_STOCK'?'Not enough central stock for this transfer.':'Stock transfer failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
return h(React.Fragment,null,h('section',{className:'moduleHead'},h('small',null,'STOCK CONTROL'),h('h1',null,'Inventory / Warehouse'),h('p',null,'Central finished goods, outlet transfers and stock accountability.')),error?h('div',{className:'authError'},error):null,
h('div',{className:'stats'},h(Card,{t:'Raw tea received',v:Number(data.raw_received_kg||0).toFixed(2)+' kg',s:'Purchase ledger'}),h(Card,{t:'QC-passed output',v:Number(data.produced_kg||0).toFixed(2)+' kg',s:'Production ledger'}),h(Card,{t:'Recorded wastage',v:Number(data.wastage_kg||0).toFixed(2)+' kg',s:'Production variance'}),h(Card,{t:'Finished stock lines',v:String((data.stock||[]).length),s:'Central inventory'})),
canTransfer?h('section',{className:'panel formrow'},h('label',{className:'field'},'Finished pack',h('select',{value:packId,onChange:e=>setPackId(e.target.value)},(data.stock||[]).map(x=>h('option',{key:x.pack_id,value:x.pack_id},x.product+' '+x.pack+'g · stock '+x.qty)))),h('label',{className:'field'},'Franchise outlet',h('select',{value:fid,onChange:e=>setFid(e.target.value)},franchises.map(x=>h('option',{key:x.id,value:x.id},x.name)))),h(Field,{label:'Transfer qty',value:qty,onChange:setQty,type:'number'}),h('button',{className:'primary fit',disabled:busy||!(data.stock||[]).length||!franchises.length,onClick:transfer},busy?'Transferring…':'Transfer to outlet')):null,
h(DataTable,{rows:data.stock||[],cols:[['product','Product'],['category','Category'],['pack','Pack g'],['qty','Central stock'],['mrp','MRP',money]],empty:'No finished goods stock yet. Complete a packaging job to create stock.'}))}

function Franchises({user}){
 const[rows,setRows]=React.useState([]),[selected,setSelected]=React.useState(null),[busy,setBusy]=React.useState(false),[error,setError]=React.useState('');
 const[form,setForm]=React.useState({name:'',division:'',district:'',upazila:'',owner_name:'',owner_phone:'',target_open_date:'',tier:'Starter',manual:30});
 async function loadRows(){try{const r=await api('franchises');setRows(r.franchises||[])}catch{setError('Could not load franchise outlets.')}}
 React.useEffect(()=>{loadRows()},[]);
 async function add(){
  if(!form.name||user.role!=='OWNER')return;setBusy(true);setError('');
  try{
   const pct=form.tier==='Manual'?Number(form.manual):D.marginTiers[form.tier];
   const r=await api('franchise.create',{method:'POST',body:{code:'TSB-'+Date.now().toString().slice(-7),name:form.name,division:form.division,district:form.district,upazila:form.upazila,owner_name:form.owner_name,owner_phone:form.owner_phone,target_open_date:form.target_open_date||undefined,margin_tier:form.tier,margin_percent:pct}});
   setForm({name:'',division:'',district:'',upazila:'',owner_name:'',owner_phone:'',target_open_date:'',tier:'Starter',manual:30});await loadRows();setSelected(Number(r.id));
  }catch(e){setError('Outlet could not be created: '+(e.code||'ERROR'))}finally{setBusy(false)}
 }
 async function updateMargin(r){
  if(user.role!=='OWNER')return;const raw=prompt('Set margin % for '+r.name+' (standard: 25 / 27 / 30, or custom):',String(r.margin_percent||25));if(raw===null)return;
  const pct=Number(raw);if(!Number.isFinite(pct)||pct<=0||pct>=100){setError('Enter a valid margin percentage between 0 and 100.');return}
  setBusy(true);setError('');try{await api('franchise.margin.update',{method:'POST',body:{id:Number(r.id),margin_percent:pct}});await loadRows()}catch(e){setError('Margin update failed: '+(e.code||'ERROR'))}finally{setBusy(false)}
 }
 const createForm=user.role==='OWNER'?h('section',{className:'panel outletCreate'},h(Title,{t:'Create outlet pipeline record',tag:'START AT LEAD'}),
  h('div',{className:'formrow outletCreateGrid'},
   h(Field,{label:'Outlet name',value:form.name,onChange:v=>setForm({...form,name:v})}),
   h(Field,{label:'Division',value:form.division,onChange:v=>setForm({...form,division:v})}),
   h(Field,{label:'District',value:form.district,onChange:v=>setForm({...form,district:v})}),
   h(Field,{label:'Upazila',value:form.upazila,onChange:v=>setForm({...form,upazila:v})}),
   h(Field,{label:'Target opening',value:form.target_open_date,onChange:v=>setForm({...form,target_open_date:v}),type:'date'}),
   h(Field,{label:'Owner / franchisee',value:form.owner_name,onChange:v=>setForm({...form,owner_name:v})}),
   h(Field,{label:'Owner phone',value:form.owner_phone,onChange:v=>setForm({...form,owner_phone:v})}),
   h('label',{className:'field'},'Margin tier',h('select',{value:form.tier,onChange:e=>setForm({...form,tier:e.target.value})},Object.keys(D.marginTiers).map(x=>h('option',{key:x},x)))),
   form.tier==='Manual'?h(Field,{label:'Manual %',value:form.manual,onChange:v=>setForm({...form,manual:v}),type:'number'}):null,
   h('button',{className:'primary fit',disabled:busy,onClick:add},busy?'Creating…':'Create pipeline outlet')
  )):null;
 const table=h(DataTable,{rows,cols:[
  ['code','Code'],['name','Outlet'],['division','Division'],['district','District'],['upazila','Upazila'],['pipeline_stage','Pipeline'],['health','Health'],
  ['sales_30d','30d sales',money],['stock_value','Stock',money],['margin_percent','Margin %'],['status','Status'],
  ['open','360°',(_,r)=>h('button',{className:'miniBtn',onClick:()=>setSelected(Number(r.id))},'Open 360°')],
  ['margin','Margin',(_,r)=>user.role==='OWNER'?h('button',{className:'miniBtn',onClick:()=>updateMargin(r)},'Set margin'):'Owner only']
 ],empty:'No franchise outlets configured yet.'});
 return h(React.Fragment,null,
  h('section',{className:'moduleHead'},h('small',null,'FRANCHISE NETWORK · PATCH 1'),h('h1',null,'Outlets / Franchise'),h('p',null,'Outlet 360°, opening pipeline, territory, checklists, people, training, health and lifecycle history.')),
  error?h('div',{className:'authError'},error):null,createForm,
  h('section',{className:'panel'},h(Title,{t:'Network outlet register',tag:rows.length+' OUTLETS'}),table),
  selected?h(Outlet360,{fid:selected,user,onClose:()=>setSelected(null),onChanged:loadRows}):null
 );
}

function Outlet360({fid,user,onClose,onChanged}){
 const[data,setData]=React.useState(null),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState(''),[tab,setTab]=React.useState('overview');
 const[profile,setProfile]=React.useState({}),[pipeline,setPipeline]=React.useState({});
 const[staffForm,setStaffForm]=React.useState({name:'',staff_role:'Outlet Staff',phone:'',joined_at:''});
 const[trainingForm,setTrainingForm]=React.useState({course_title:'POS & Retail Operations',status:'pending',outlet_staff_id:'',trainer:'',expires_at:''});
 const[docForm,setDocForm]=React.useState({document_type:'franchise_document',title:'',file_path:''});
 const[healthForm,setHealthForm]=React.useState({sales_score:0,stock_score:0,settlement_score:0,compliance_score:0,notes:''});
 const canOps=['OWNER','OPERATIONS'].includes(user.role),canField=['OWNER','OPERATIONS','REGIONAL'].includes(user.role);
 async function load(){try{const r=await api('franchise.360?franchise_id='+encodeURIComponent(fid));setData(r);setProfile({...r.profile,name:r.franchise.name,district:r.franchise.district||'',upazila:r.franchise.upazila||'',address:r.franchise.address||''});setPipeline({...r.pipeline})}catch(e){setMsg('Outlet 360° could not be loaded: '+(e.code||'ERROR'))}}
 React.useEffect(()=>{load()},[fid]);
 async function act(fn){setBusy(true);setMsg('');try{await fn();await load();if(onChanged)await onChanged()}catch(e){const code=e.code||'ACTION_FAILED';setMsg(code==='OPENING_CHECKLIST_INCOMPLETE'?'Complete every required opening checklist item before moving the outlet to Live.':code==='CLOSURE_CHECKLIST_INCOMPLETE'?'Complete every required closure checklist item before closing the outlet.':code==='OWNER_APPROVAL_REQUIRED'?'Founder approval is required for suspension, closure or reactivation.':code)}finally{setBusy(false)}}
 async function saveProfile(){await act(()=>api('franchise.profile.update',{method:'POST',body:{franchise_id:fid,...profile}}))}
 async function savePipeline(){await act(()=>api('franchise.pipeline.update',{method:'POST',body:{franchise_id:fid,stage:pipeline.stage,target_open_date:pipeline.target_open_date||null,next_action:pipeline.next_action||'',blocking_reason:pipeline.blocking_reason||''}}))}
 async function toggleCheck(item){await act(()=>api('franchise.checklist.toggle',{method:'POST',body:{franchise_id:fid,id:Number(item.id),completed:!Number(item.completed),label:item.item_label,notes:item.notes||''}}))}
 async function addStaff(){if(!staffForm.name||!staffForm.staff_role)return;await act(async()=>{await api('franchise.staff.create',{method:'POST',body:{franchise_id:fid,...staffForm}});setStaffForm({name:'',staff_role:'Outlet Staff',phone:'',joined_at:''})})}
 async function toggleStaff(r){await act(()=>api('franchise.staff.toggle',{method:'POST',body:{franchise_id:fid,id:Number(r.id),active:!Number(r.active)}}))}
 async function saveTraining(){if(!trainingForm.course_title)return;await act(async()=>{await api('franchise.training.save',{method:'POST',body:{franchise_id:fid,...trainingForm,outlet_staff_id:trainingForm.outlet_staff_id?Number(trainingForm.outlet_staff_id):null}});setTrainingForm({course_title:'POS & Retail Operations',status:'pending',outlet_staff_id:'',trainer:'',expires_at:''})})}
 async function saveDocument(){if(!docForm.title)return;await act(async()=>{await api('franchise.document.create',{method:'POST',body:{franchise_id:fid,...docForm}});setDocForm({document_type:'franchise_document',title:'',file_path:''})})}
 async function saveHealth(){await act(()=>api('franchise.health.save',{method:'POST',body:{franchise_id:fid,...healthForm}}))}
 if(!data)return msg?h('div',{className:'authError'},msg):h(Loading);

 const f=data.franchise,p=data.profile||{},pl=data.pipeline||{},ch=data.checklists||{},health=data.health_latest,ops=data.operations||{};
 const stages=['lead','verification','agreement','shop_ready','training','stock_ready','pos_ready','launch','live'].concat(user.role==='OWNER'?['suspended','closed']:[]);
 const tabs=[['overview','Overview'],['opening','Opening'],['people','People & Training'],['documents','Documents'],['health','Health'],['dailyops','Daily Ops'],['closure','Closure'],['timeline','Timeline']];
 function checklist(type){
  const rows=ch[type]||[],progress=ch[type+'_progress']||0;
  const items=h('div',{className:'checklist'},rows.map(x=>h('button',{key:x.id,className:Number(x.completed)?'done':'',disabled:busy||!canField,onClick:()=>toggleCheck(x)},h('i',null,Number(x.completed)?'✓':'○'),h('span',null,x.item_label),h('small',null,x.required?'Required':'Optional'))));
  return h('section',{className:'panel checklistPanel'},h(Title,{t:type==='opening'?'Opening checklist':'Closure checklist',tag:String(progress)+'%'}),items);
 }
 const summary=h('div',{className:'outlet360Summary'},
  h('div',null,h('span',null,'Pipeline'),h('b',null,String(pl.stage||'lead').replaceAll('_',' '))),
  h('div',null,h('span',null,'Health'),h('b',null,health?String(health.health).toUpperCase():'NEW')),
  h('div',null,h('span',null,'30d Sales'),h('b',null,money(data.sales?.sales_30d||0))),
  h('div',null,h('span',null,'Outlet Stock'),h('b',null,money(data.stock_value||0))),
  h('div',null,h('span',null,'Opening'),h('b',null,String(ch.opening_progress||0)+'%')),
  h('div',null,h('span',null,'Margin'),h('b',null,String(f.margin_percent||0)+'%'))
 );
 const profileFacts=h('div',{className:'profileFacts'},
  h('div',null,h('span',null,'Owner'),h('b',null,p.owner_name||'—'),h('small',null,p.owner_phone||'')),
  h('div',null,h('span',null,'Territory'),h('b',null,[p.division,f.district,f.upazila].filter(Boolean).join(' → ')||'Unassigned'),h('small',null,p.territory_code||'')),
  h('div',null,h('span',null,'Agreement'),h('b',null,p.agreement_no||'—'),h('small',null,p.agreement_date||'')),
  h('div',null,h('span',null,'Target / Opened'),h('b',null,p.target_open_date||'—'),h('small',null,f.opened_at?'Opened '+f.opened_at:'Not live yet'))
 );
 const profileEditor=canOps?h('div',{className:'formrow outletProfileForm'},
  h(Field,{label:'Outlet name',value:profile.name||'',onChange:v=>setProfile({...profile,name:v})}),
  h(Field,{label:'Owner name',value:profile.owner_name||'',onChange:v=>setProfile({...profile,owner_name:v})}),
  h(Field,{label:'Owner phone',value:profile.owner_phone||'',onChange:v=>setProfile({...profile,owner_phone:v})}),
  h(Field,{label:'Owner email',value:profile.owner_email||'',onChange:v=>setProfile({...profile,owner_email:v}),type:'email'}),
  h(Field,{label:'Division',value:profile.division||'',onChange:v=>setProfile({...profile,division:v})}),
  h(Field,{label:'District',value:profile.district||'',onChange:v=>setProfile({...profile,district:v})}),
  h(Field,{label:'Upazila',value:profile.upazila||'',onChange:v=>setProfile({...profile,upazila:v})}),
  h(Field,{label:'Territory code',value:profile.territory_code||'',onChange:v=>setProfile({...profile,territory_code:v})}),
  h(Field,{label:'Agreement no',value:profile.agreement_no||'',onChange:v=>setProfile({...profile,agreement_no:v})}),
  h(Field,{label:'Target open',value:profile.target_open_date||'',onChange:v=>setProfile({...profile,target_open_date:v}),type:'date'}),
  h('button',{className:'primary fit',disabled:busy,onClick:saveProfile},'Save profile')
 ):null;
 const stageOrder=['lead','verification','agreement','shop_ready','training','stock_ready','pos_ready','launch','live'],stageIndex=stageOrder.indexOf(pl.stage);
 const rail=h('div',{className:'pipelineRail'},stageOrder.map((x,i)=>h('div',{key:x,className:i<=stageIndex?'passed':''},h('i'),h('span',null,x.replaceAll('_',' ')))));
 const pipelineEditor=canOps?h('div',{className:'formrow'},
  h('label',{className:'field'},'Stage',h('select',{value:pipeline.stage||'lead',onChange:e=>setPipeline({...pipeline,stage:e.target.value})},stages.map(x=>h('option',{key:x,value:x},x.replaceAll('_',' '))))),
  h(Field,{label:'Target opening',value:pipeline.target_open_date||'',onChange:v=>setPipeline({...pipeline,target_open_date:v}),type:'date'}),
  h(Field,{label:'Next action',value:pipeline.next_action||'',onChange:v=>setPipeline({...pipeline,next_action:v})}),
  h(Field,{label:'Blocker / reason',value:pipeline.blocking_reason||'',onChange:v=>setPipeline({...pipeline,blocking_reason:v})}),
  h('button',{className:'primary fit',disabled:busy,onClick:savePipeline},'Update pipeline')
 ):null;

 let content;
 if(tab==='overview'){
  const left=h('section',{className:'panel'},h(Title,{t:'Outlet / owner profile',tag:'360°'}),profileFacts,profileEditor);
  const right=h('section',{className:'panel'},h(Title,{t:'Opening pipeline',tag:String(pl.stage||'lead').toUpperCase()}),rail,pipelineEditor,pl.next_action?h('div',{className:'notice'},h('b',null,'Next action'),h('span',null,pl.next_action)):null);
  const live=h('section',{className:'panel'},h(Title,{t:'Current stock / settlement',tag:'LIVE'}),h('div',{className:'miniMetrics'},h('div',null,h('span',null,'Stock lines'),h('b',null,String((data.stock||[]).length))),h('div',null,h('span',null,'30d receipts'),h('b',null,String(data.sales?.receipts_30d||0))),h('div',null,h('span',null,'Last sale'),h('b',null,data.sales?.last_sale_at||'—'))),h(DataTable,{rows:(data.settlements||[]).slice(0,5),cols:[['period_end','Period'],['verified_sales','Sales',money],['earned_margin','Margin',money],['net_payable','Payable',money],['status','Status']],empty:'No settlement history yet.'}));
  content=h(React.Fragment,null,h('div',{className:'twocol'},left,right),h('div',{className:'twocol'},checklist('opening'),live));
 }else if(tab==='opening'){
  const stock=h('section',{className:'panel'},h(Title,{t:'Launch readiness',tag:String(ch.opening_progress||0)+'%'}),h('p',{className:'muted'},'Lead → Verification → Agreement → Shop Ready → Training → Stock Ready → POS Ready → Launch → Live.'),h(DataTable,{rows:data.stock||[],cols:[['product','Product'],['grams','Pack g'],['qty','Qty'],['mrp','MRP',money]],empty:'No opening stock at this outlet yet.'}));
  content=h('div',{className:'twocol'},checklist('opening'),stock);
 }else if(tab==='people'){
  const staffFormView=canOps?h('div',{className:'formrow'},h(Field,{label:'Name',value:staffForm.name,onChange:v=>setStaffForm({...staffForm,name:v})}),h(Field,{label:'Role',value:staffForm.staff_role,onChange:v=>setStaffForm({...staffForm,staff_role:v})}),h(Field,{label:'Phone',value:staffForm.phone,onChange:v=>setStaffForm({...staffForm,phone:v})}),h(Field,{label:'Joined',value:staffForm.joined_at,onChange:v=>setStaffForm({...staffForm,joined_at:v}),type:'date'}),h('button',{className:'primary fit',disabled:busy,onClick:addStaff},'Add staff')):null;
  const staffTable=h(DataTable,{rows:data.staff||[],cols:[['name','Name'],['staff_role','Role'],['phone','Phone'],['joined_at','Joined'],['active','Active',v=>Number(v)?'Yes':'No'],['action','Action',(_,r)=>canOps?h('button',{className:'miniBtn',onClick:()=>toggleStaff(r)},Number(r.active)?'Deactivate':'Activate'):'—']],empty:'No outlet staff recorded.'});
  const people=h('section',{className:'panel'},h(Title,{t:'Outlet staff',tag:String((data.staff||[]).filter(x=>Number(x.active)).length)+' ACTIVE'}),staffFormView,staffTable);
  const trainingFormView=canField?h('div',{className:'formrow'},h(Field,{label:'Course',value:trainingForm.course_title,onChange:v=>setTrainingForm({...trainingForm,course_title:v})}),h('label',{className:'field'},'Staff',h('select',{value:trainingForm.outlet_staff_id,onChange:e=>setTrainingForm({...trainingForm,outlet_staff_id:e.target.value})},h('option',{value:''},'Outlet / all staff'),(data.staff||[]).filter(x=>Number(x.active)).map(x=>h('option',{key:x.id,value:x.id},x.name)))),h('label',{className:'field'},'Status',h('select',{value:trainingForm.status,onChange:e=>setTrainingForm({...trainingForm,status:e.target.value})},['pending','scheduled','completed','expired'].map(x=>h('option',{key:x},x)))),h(Field,{label:'Trainer',value:trainingForm.trainer,onChange:v=>setTrainingForm({...trainingForm,trainer:v})}),h('button',{className:'primary fit',disabled:busy,onClick:saveTraining},'Save training')):null;
  const trainingTable=h(DataTable,{rows:data.training||[],cols:[['course_title','Course'],['staff_name','Staff'],['status','Status'],['completed_at','Completed'],['expires_at','Expires'],['trainer','Trainer']],empty:'No training records yet.'});
  const training=h('section',{className:'panel'},h(Title,{t:'Training',tag:'READINESS'}),trainingFormView,trainingTable);
  content=h('div',{className:'twocol'},people,training);
 }else if(tab==='documents'){
  const form=canOps?h('div',{className:'formrow'},h(Field,{label:'Document title',value:docForm.title,onChange:v=>setDocForm({...docForm,title:v})}),h(Field,{label:'Type',value:docForm.document_type,onChange:v=>setDocForm({...docForm,document_type:v})}),h(Field,{label:'File / reference path',value:docForm.file_path,onChange:v=>setDocForm({...docForm,file_path:v})}),h('button',{className:'primary fit',disabled:busy,onClick:saveDocument},'Register document')):null;
  content=h('section',{className:'panel'},h(Title,{t:'Outlet documents',tag:'REGISTRY'}),form,h(DataTable,{rows:data.documents||[],cols:[['created_at','Date'],['document_type','Type'],['title','Title'],['file_path','Reference'],['status','Status']],empty:'No outlet documents registered.'}));
 }else if(tab==='health'){
  const score=health?h('div',{className:'healthHero'},h('b',null,Number(health.total_score||0).toFixed(1)),h('span',null,String(health.health).toUpperCase()),h('small',null,'Sales 35% · Stock 25% · Settlement 25% · Compliance 15%')):h(Empty,{text:'No health check yet.'});
  const inputs=canField?h('div',{className:'healthInputs'},[['sales_score','Sales score'],['stock_score','Stock score'],['settlement_score','Settlement score'],['compliance_score','Compliance score']].map(([k,l])=>h(Field,{key:k,label:l,value:healthForm[k],onChange:v=>setHealthForm({...healthForm,[k]:v}),type:'number'})),h(Field,{label:'Notes',value:healthForm.notes,onChange:v=>setHealthForm({...healthForm,notes:v})}),h('button',{className:'primary fit',disabled:busy,onClick:saveHealth},'Record health check')):null;
  const current=h('section',{className:'panel'},h(Title,{t:'Outlet health score',tag:health?String(health.health).toUpperCase():'NEW'}),score,inputs);
  const history=h('section',{className:'panel'},h(Title,{t:'Health history',tag:'TREND'}),h(DataTable,{rows:data.health_history||[],cols:[['checked_at','Checked'],['total_score','Score'],['health','Health'],['sales_score','Sales'],['stock_score','Stock'],['settlement_score','Settlement'],['compliance_score','Compliance']],empty:'No health history yet.'}));
  content=h('div',{className:'twocol'},current,history);

 }else if(tab==='dailyops'){
  const openTasks=(ops.tasks||[]).filter(x=>!['done','cancelled'].includes(x.status));
  const openTickets=(ops.tickets||[]).filter(x=>!['resolved','closed','cancelled'].includes(x.status));
  content=h(React.Fragment,null,
   h('div',{className:'stats outletDailyStats'},
    h(Card,{t:'Open tasks',v:String(openTasks.length),s:String(openTasks.filter(x=>x.sla_status==='overdue').length)+' overdue'}),
    h(Card,{t:'Open tickets',v:String(openTickets.length),s:String(openTickets.filter(x=>x.sla_status==='overdue').length)+' overdue'}),
    h(Card,{t:'Field visits',v:String((ops.visits||[]).length),s:'Scheduled + completed history'}),
    h(Card,{t:'Compliance',v:String((ops.compliance||[]).filter(x=>!x.resolved_at&&x.status!=='compliant').length),s:'Open corrective actions'})
   ),
   h('div',{className:'twocol'},
    h('section',{className:'panel'},h(Title,{t:'Tasks & follow-ups',tag:'SLA'}),h(DataTable,{rows:(ops.tasks||[]).slice(0,30),cols:[['title','Task'],['priority','Priority'],['assigned_to','Assigned'],['due_at','Due'],['sla_status','SLA'],['status','Status']],empty:'No tasks for this outlet.'})),
    h('section',{className:'panel'},h(Title,{t:'Support tickets',tag:'ISSUES'}),h(DataTable,{rows:(ops.tickets||[]).slice(0,30),cols:[['ticket_no','Ticket'],['category','Category'],['subject','Subject'],['priority','Priority'],['sla_status','SLA'],['status','Status']],empty:'No support tickets for this outlet.'}))
   ),
   h('div',{className:'twocol'},
    h('section',{className:'panel'},h(Title,{t:'Field visits',tag:'INSPECTION'}),h(DataTable,{rows:(ops.visits||[]).slice(0,30),cols:[['scheduled_at','Scheduled'],['visit_type','Type'],['visitor','Visitor'],['status','Status'],['overall_score','Score'],['next_visit_at','Next']],empty:'No field visits for this outlet.'})),
    h('section',{className:'panel'},h(Title,{t:'Compliance',tag:'SOP'}),h(DataTable,{rows:(ops.compliance||[]).slice(0,30),cols:[['checked_at','Checked'],['overall_score','Score'],['status','Status'],['corrective_action','Corrective'],['corrective_due_at','Due'],['resolved_at','Resolved']],empty:'No compliance checks for this outlet.'}))
   ),
   h('div',{className:'twocol'},
    h('section',{className:'panel'},h(Title,{t:'Communication notes',tag:'CALL / WHATSAPP / MEETING'}),h(DataTable,{rows:(ops.communications||[]).slice(0,40),cols:[['created_at','Date'],['channel','Channel'],['direction','Direction'],['subject','Subject'],['note','Note'],['follow_up_at','Follow-up']],empty:'No communication notes for this outlet.'})),
    h('section',{className:'panel'},h(Title,{t:'Marketing execution',tag:'OUTLET'}),h(DataTable,{rows:(ops.marketing||[]).slice(0,30),cols:[['campaign_name','Campaign'],['status','Status'],['priority','Priority'],['assigned_to','Assigned'],['due_at','Due'],['sla_status','SLA'],['execution_verified','Verified',v=>Number(v)?'Yes':'No']],empty:'No marketing executions for this outlet.'}))
   )
  );
 }else if(tab==='closure'){
  const controls=h('section',{className:'panel'},h(Title,{t:'Suspension / closure control',tag:'OWNER FINAL'}),h('p',{className:'muted'},'Operations can prepare the closure checklist. Only Founder can move the pipeline to Suspended or Closed.'),p.operational_state==='suspended'?h('div',{className:'notice'},h('b',null,'SUSPENDED'),h('span',null,p.suspension_reason||'Owner-approved suspension')):null,p.closure_reason?h('div',{className:'notice'},h('b',null,'Closure reason'),h('span',null,p.closure_reason)):null);
  content=h('div',{className:'twocol'},checklist('closure'),controls);
 }else{
  const rows=data.timeline||[];
  content=h('section',{className:'panel'},h(Title,{t:'Complete outlet timeline',tag:String(rows.length)+' EVENTS'}),h('div',{className:'timeline outletTimeline'},rows.map((e,i)=>h('div',{className:'timelineRow',key:i},h('i'),h('div',null,h('span',null,(e.occurred_at||'')+' · '+String(e.event_type||'').replaceAll('_',' ')),h('b',null,e.title||'Event'),h('p',null,e.detail||''))))));
 }
 const head=h('div',{className:'outlet360Head'},h('div',null,h('button',{className:'miniBtn',onClick:onClose},'← Network'),h('small',null,f.code+' · '+(p.division||'Unassigned')+' / '+(f.district||'Unassigned')),h('h2',null,f.name),h('p',null,(p.owner_name||'Owner not set')+(p.owner_phone?' · '+p.owner_phone:''))),h('div',{className:'outlet360Badges'},h(Pill,{kind:f.status==='active'?'success':''},String(f.status).toUpperCase()),h(Pill,null,String(pl.stage||'lead').replaceAll('_',' ').toUpperCase())));
 const nav=h('div',{className:'outletTabs'},tabs.map(([k,l])=>h('button',{key:k,className:tab===k?'active':'',onClick:()=>setTab(k)},l)));
 return h('section',{className:'outlet360'},head,summary,msg?h('div',{className:'notice'},h('b',null,'Outlet 360°'),h('span',null,msg)):null,nav,content);
}
function POS(){const[cart,setCart]=React.useState([]),[franchises,setFranchises]=React.useState([]),[stock,setStock]=React.useState([]),[fid,setFid]=React.useState(''),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState('');
React.useEffect(()=>{api('franchises').then(r=>{const rows=(r.franchises||[]).filter(x=>x.status!=='closed');setFranchises(rows);if(rows[0])setFid(String(rows[0].id))}).catch(()=>{})},[]);
React.useEffect(()=>{if(!fid){setStock([]);return}api('inventory.franchise?franchise_id='+encodeURIComponent(fid)).then(r=>setStock(r.stock||[])).catch(()=>setStock([]))},[fid]);
function addItem(p){const found=cart.find(x=>x.pack_id===p.pack_id);const current=found?.qty||0;if(current>=Number(p.qty))return;setCart(found?cart.map(x=>x.pack_id===p.pack_id?{...x,qty:x.qty+1}:x):[...cart,{...p,qty:1}])}
function removeOne(id){const found=cart.find(x=>x.pack_id===id);if(!found)return;setCart(found.qty<=1?cart.filter(x=>x.pack_id!==id):cart.map(x=>x.pack_id===id?{...x,qty:x.qty-1}:x))}
const total=cart.reduce((a,b)=>a+Number(b.mrp)*Number(b.qty),0);
async function pay(){if(!cart.length||!fid)return;setBusy(true);setMsg('');try{const r=await api('sale.create',{method:'POST',body:{franchise_id:Number(fid),payment_method:'cash',items:cart.map(x=>({product_pack_id:Number(x.pack_id),qty:Number(x.qty)}))}});setCart([]);setMsg('Sale saved · '+r.receipt+' · total '+money(r.gross_amount)+' · franchise earned '+money(r.earned_margin));const inv=await api('inventory.franchise?franchise_id='+encodeURIComponent(fid));setStock(inv.stock||[])}catch(e){setMsg('Sale could not be saved: '+(e.code||'ERROR'))}finally{setBusy(false)}}
return h('div',{className:'pos'},h('section',{className:'panel'},h(Title,{t:'Point of Sale',tag:'LIVE OUTLET STOCK'}),franchises.length?h('div',{className:'toolbar'},h('label',{className:'field'},'Outlet',h('select',{value:fid,onChange:e=>{setFid(e.target.value);setCart([]);setMsg('')}},franchises.map(x=>h('option',{key:x.id,value:x.id},x.name+' · '+x.margin_percent+'%')))),h(Pill,{kind:'success'},stock.length+' stock lines')):h('div',{className:'notice'},h('b',null,'Outlet required'),h('span',null,'Founder must create an outlet first.')),
stock.length?h('div',{className:'products'},stock.map(p=>h('button',{key:p.pack_id,onClick:()=>addItem(p)},h('small',null,p.category),h('b',null,p.product+' '+p.pack+'g'),h('strong',null,money(p.mrp)),h('span',{className:'stockMeta'},'Stock '+p.qty)))):h(Empty,{text:fid?'No stock at this outlet. Transfer finished goods from Inventory first.':'Select an outlet.'})),
h('section',{className:'panel cart'},h(Title,{t:'Current sale',tag:cart.reduce((a,b)=>a+b.qty,0)+' ITEMS'}),msg?h('div',{className:'notice'},msg):null,cart.length?cart.map(x=>h('div',{className:'line',key:x.pack_id},h('span',null,x.product+' '+x.pack+'g × '+x.qty),h('b',null,money(Number(x.mrp)*x.qty)),h('button',{onClick:()=>removeOne(x.pack_id)},'−'))):h(Empty,{text:'Add products from available outlet stock.'}),h('div',{className:'total'},h('span',null,'Total'),h('b',null,money(total))),h('button',{className:'primary',disabled:busy||!cart.length||!fid,onClick:pay},busy?'Saving sale…':'Complete verified sale →')))}
function Settlements({user}){const[rows,setRows]=React.useState([]),[period,setPeriod]=React.useState(new Date().toISOString().slice(0,7)),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState('');
async function loadRows(){try{const r=await api('settlements');setRows(r.settlements||[])}catch{setMsg('Settlement records could not be loaded.')}}
React.useEffect(()=>{loadRows()},[]);
async function generate(){setBusy(true);setMsg('');try{await api('settlement.generate',{method:'POST',body:{period}});setMsg('Settlement review generated for '+period+'.');await loadRows()}catch(e){setMsg('Generation failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
async function lock(id){if(!confirm('Lock this settlement? Locked periods cannot be regenerated.'))return;setBusy(true);try{await api('settlement.lock',{method:'POST',body:{id}});await loadRows()}catch(e){setMsg('Lock failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
async function paid(id){if(!confirm('Mark this locked settlement as paid?'))return;setBusy(true);try{await api('settlement.paid',{method:'POST',body:{id}});await loadRows()}catch(e){setMsg('Update failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
const canGenerate=['OWNER','FINANCE'].includes(user.role);
return h(React.Fragment,null,
h('section',{className:'moduleHead'},h('small',null,'MONTH-END CONTROL'),h('h1',null,'Franchise settlement'),h('p',null,'Stock received is not profit. Verified eligible sale creates franchise margin; Owner locks the reviewed period.')),
msg?h('div',{className:'notice'},msg):null,
canGenerate?h('section',{className:'panel formrow'},h(Field,{label:'Settlement month',value:period,onChange:setPeriod,type:'month'}),h('button',{className:'primary fit',disabled:busy,onClick:generate},busy?'Working…':'Generate / Refresh review')):null,
rows.length?h('div',{className:'tablewrap panel'},h('table',null,h('thead',null,h('tr',null,['Outlet','Period','Opening stock','Received stock','Verified sales','Earned margin','Closing stock','Net payable','Status','Action'].map(x=>h('th',{key:x},x)))),h('tbody',null,rows.map(r=>h('tr',{key:r.id},h('td',null,h('b',null,r.outlet)),h('td',null,r.period_start+' → '+r.period_end),h('td',null,money(r.opening_stock_value)),h('td',null,money(r.stock_received_value)),h('td',null,money(r.verified_sales)),h('td',null,money(r.earned_margin)),h('td',null,money(r.closing_stock_value)),h('td',null,h('b',null,money(r.net_payable))),h('td',null,h(Pill,{kind:r.status==='paid'?'success':''},String(r.status).toUpperCase())),h('td',null,r.status==='review'&&user.role==='OWNER'?h('button',{className:'miniBtn',disabled:busy,onClick:()=>lock(r.id)},'Lock'):r.status==='locked'&&['OWNER','FINANCE'].includes(user.role)?h('button',{className:'miniBtn',disabled:busy,onClick:()=>paid(r.id)},'Mark paid'):'—')))))):h(Empty,{text:'No settlement periods generated yet.'}))}
function Finance({user}){const[summary,setSummary]=React.useState({}),[expenses,setExpenses]=React.useState([]),[settings,setSettings]=React.useState({}),[name,setName]=React.useState(''),[amount,setAmount]=React.useState(''),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState('');
async function loadLive(){try{const[a,b,s]=await Promise.all([api('finance.summary'),api('expenses'),api('settings')]);setSummary(a);setExpenses(b.expenses||[]);setSettings(s.settings||{})}catch{setMsg('Finance data could not be loaded.')}}
React.useEffect(()=>{loadLive()},[]);
async function add(){if(!name||!amount)return;setBusy(true);setMsg('');try{await api('expense.create',{method:'POST',body:{name,amount:Number(amount)}});setName('');setAmount('');await loadLive()}catch(e){setMsg('Expense could not be saved: '+(e.code||'ERROR'))}finally{setBusy(false)}}
async function savePolicy(){if(user.role!=='OWNER')return;setBusy(true);setMsg('');try{await api('settings.save',{method:'POST',body:{settings:{management_share_active_tier:settings.management_share_active_tier||'Base',management_share_manual_percent:settings.management_share_manual_percent||20,tax_provision_percent:settings.tax_provision_percent||0}}});setMsg('Founder finance policy saved.');await loadLive()}catch(e){setMsg('Policy could not be saved: '+(e.code||'ERROR'))}finally{setBusy(false)}}
return h(React.Fragment,null,
h('section',{className:'moduleHead'},h('small',null,'FINANCE CONTROL'),h('h1',null,'Finance & Accounts'),h('p',null,'Verified sales, earned franchise margin, approved operating expense and performance share.')),
msg?h('div',{className:'notice'},msg):null,
h('div',{className:'stats'},h(Card,{t:'Verified revenue',v:money(summary.verified_sales),s:'POS source'}),h(Card,{t:'Franchise earned margin',v:money(summary.franchise_earned_margin),s:'Verified sales only'}),h(Card,{t:'Approved expenses',v:money(summary.approved_expenses),s:expenses.length+' entries'}),h(Card,{t:'Distributable profit',v:money(summary.distributable_profit),s:'Performance-share base'})),
h('div',{className:'twocol'},
h('section',{className:'panel'},h(Title,{t:'Operating expenses',tag:'LIVE LEDGER'}),['OWNER','FINANCE'].includes(user.role)?h('div',{className:'formrow'},h(Field,{label:'Expense',value:name,onChange:setName}),h(Field,{label:'Amount',value:amount,type:'number',onChange:setAmount}),h('button',{className:'primary fit',disabled:busy,onClick:add},busy?'Saving…':'Add expense')):null,h(DataTable,{rows:expenses,cols:[['date','Date'],['name','Expense'],['amount','Amount',money]],empty:'No operating expenses entered.'})),
h('section',{className:'panel'},h(Title,{t:'Franchise & Retail Operations performance share',tag:'P&L ONLY'}),h('label',{className:'field'},'Active tier',h('select',{disabled:user.role!=='OWNER',value:settings.management_share_active_tier||'Base',onChange:e=>setSettings({...settings,management_share_active_tier:e.target.value})},['Base','Growth','Elite','Manual'].map(x=>h('option',{key:x},x)))),settings.management_share_active_tier==='Manual'?h(Field,{label:'Manual %',value:settings.management_share_manual_percent||20,onChange:v=>setSettings({...settings,management_share_manual_percent:v}),type:'number'}):null,h(Field,{label:'VAT / tax provision % (policy)',value:settings.tax_provision_percent||0,onChange:v=>setSettings({...settings,tax_provision_percent:v}),type:'number'}),user.role==='OWNER'?h('button',{className:'primary fit',disabled:busy,onClick:savePolicy},'Save Founder policy'):null,h('div',{className:'profitbox'},h('span',null,'Distributable profit'),h('b',null,money(summary.distributable_profit)),h('span',null,'management '+Number(summary.management_percent||0)+'%'),h('b',null,money(summary.management_share)),h('span',null,'Tea Shop BD remaining'),h('strong',null,money(summary.company_net_after_management))),h('p',{className:'muted'},'Tax provision is stored as an editable policy line and is not automatically applied to an assumed tax base.'))))}

function TeaHub(){
 const[rows,setRows]=React.useState([]),[q,setQ]=React.useState(''),[cat,setCat]=React.useState('All'),[selected,setSelected]=React.useState(null),[detail,setDetail]=React.useState(null),[msg,setMsg]=React.useState('');
 async function load(){try{const r=await api('tea.overview');setRows(r.teas||[])}catch{setMsg('Tea master could not be loaded.')}}
 React.useEffect(()=>{load()},[]);
 async function openTea(r){setSelected(r);setDetail(null);setMsg('');try{const d=await api('tea.history?product_id='+encodeURIComponent(r.id));setDetail(d)}catch(e){setMsg('Tea history could not be loaded: '+(e.code||'ERROR'))}}
 const cats=['All',...Array.from(new Set(rows.map(x=>x.category)))];
 const filtered=rows.filter(x=>(cat==='All'||x.category===cat)&&(!q||String(x.name+' '+x.sku).toLowerCase().includes(q.toLowerCase())));
 const masterHead=h('thead',null,h('tr',null,['Code','Tea name','Category','Buy / kg','SKUs','Finished stock','Last production'].map(x=>h('th',{key:x},x))));
 const masterRows=filtered.map(r=>h('tr',{key:r.id,className:selected&&Number(selected.id)===Number(r.id)?'selectedRow':'',onClick:()=>openTea(r)},
  h('td',null,r.sku),h('td',null,h('button',{className:'teaLink'},r.name)),h('td',null,r.category),h('td',null,money(r.purchase_cost_per_kg)),h('td',null,r.sku_count),h('td',null,Number(r.finished_stock_qty||0).toFixed(2)),h('td',null,r.last_produced_at||'—')
 ));
 const masterBody=filtered.length?h('div',{className:'tablewrap'},h('table',null,masterHead,h('tbody',null,masterRows))):h(Empty,{text:'No tea matches the filter.'});
 let detailBody;
 if(!selected) detailBody=h(Empty,{text:'Select any tea to see purchase, blend, packaging, stock, outlet and POS history.'});
 else if(!detail) detailBody=h(Loading);
 else {
  const packHead=h('thead',null,h('tr',null,['Pack','MRP','Stock'].map(x=>h('th',{key:x},x))));
  const packRows=(detail.packs||[]).map(x=>h('tr',{key:x.id},h('td',null,x.grams+'g'),h('td',null,money(x.mrp)),h('td',null,Number(x.stock_qty||0).toFixed(2))));
  const timelineRows=(detail.events||[]).slice(0,40).map((e,i)=>h('div',{className:'timelineRow',key:i},
   h('i'),h('div',null,h('span',null,(e.event_at||'')+' · '+e.type),h('b',null,e.reference||'—'),h('p',null,e.detail||'')),h('strong',null,e.amount!==undefined&&e.amount!==null?money(e.amount):'')
  ));
  detailBody=h(React.Fragment,null,
   h('div',{className:'miniMetrics'},
    h('div',null,h('span',null,'Current buy / kg'),h('b',null,money(detail.tea.purchase_cost_per_kg))),
    h('div',null,h('span',null,'Pack / SKU'),h('b',null,String((detail.packs||[]).length))),
    h('div',null,h('span',null,'Timeline events'),h('b',null,String((detail.events||[]).length)))
   ),
   h('div',{className:'historyFlow'},'Purchase → Blend / Production → QC → Packaging → Warehouse → Outlet → POS Sale → Settlement / Profit'),
   h('div',{className:'tablewrap compactTable'},h('table',null,packHead,h('tbody',null,packRows))),
   h('div',{className:'timeline'},timelineRows.length?timelineRows:h(Empty,{text:'No transaction history yet.'}))
  );
 }
 return h(React.Fragment,null,
  h('section',{className:'moduleHead'},h('small',null,'MASTER LIFECYCLE HUB'),h('h1',null,'Tea'),h('p',null,'Every Tea Shop BD tea name in one place: purchase → raw tea → blend → QC → pack → stock → outlet → POS sale → margin → profitability.')),
  msg?h('div',{className:'notice'},msg):null,
  h('section',{className:'panel teaToolbar'},h(Field,{label:'Search tea',value:q,onChange:setQ}),h('label',{className:'field'},'Category',h('select',{value:cat,onChange:e=>setCat(e.target.value)},cats.map(x=>h('option',{key:x},x)))),h(Pill,{kind:'success'},filtered.length+' of '+rows.length+' teas')),
  h('div',{className:'teaLayout'},
   h('section',{className:'panel teaMaster'},h(Title,{t:'Tea master',tag:'99 NAMES'}),masterBody),
   h('section',{className:'panel teaDetail'},h(Title,{t:selected?selected.name+' · complete history':'Tea history',tag:selected?'TRACE':'SELECT TEA'}),detailBody)
  )
 );
}
function PurchaseHub(){const[tab,setTab]=React.useState('purchase');return h(React.Fragment,null,h('div',{className:'hubTabs'},h('button',{className:tab==='purchase'?'active':'',onClick:()=>setTab('purchase')},'Tea Purchase & Raw Stock'),h('button',{className:tab==='suppliers'?'active':'',onClick:()=>setTab('suppliers')},'Suppliers & Payables')),tab==='purchase'?h(Purchases):h(Suppliers))}
function ProductsSKU(){const[rows,setRows]=React.useState([]),[msg,setMsg]=React.useState('');React.useEffect(()=>{api('tea.overview').then(r=>setRows(r.teas||[])).catch(()=>setMsg('Product / SKU data could not be loaded.'))},[]);return h(React.Fragment,null,h('section',{className:'moduleHead'},h('small',null,'FINISHED PRODUCT MASTER'),h('h1',null,'Products / SKU'),h('p',null,'Tea master linked to pack configuration, MRP, finished stock and production history.')),msg?h('div',{className:'notice'},msg):null,h(DataTable,{rows,cols:[['sku','Code'],['name','Tea'],['category','Category'],['purchase_cost_per_kg','Tea cost/kg',money],['sku_count','Pack SKUs'],['finished_stock_qty','Finished stock']],empty:'No products found.'}))}
function QCWastage(){
 const[qc,setQc]=React.useState([]),[waste,setWaste]=React.useState([]),[msg,setMsg]=React.useState('');
 React.useEffect(()=>{Promise.all([api('workspace.records?module=qc'),api('workspace.records?module=wastage')]).then(([a,b])=>{setQc(a.records||[]);setWaste(b.records||[])}).catch(()=>setMsg('QC / wastage records could not be loaded.'))},[]);
 const qcTable=h(DataTable,{rows:qc,cols:[['checked_at','Checked'],['tea','Tea'],['batch_no','Batch'],['check_type','Type'],['status','Status'],['moisture_percent','Moisture %'],['notes','Notes']],empty:'No QC checks recorded yet.'});
 const wasteTable=h(DataTable,{rows:waste,cols:[['created_at','Date'],['tea','Tea'],['reference_type','Source'],['qty_kg','Qty kg'],['value_amount','Value',money],['reason','Reason'],['status','Status']],empty:'No wastage records yet.'});
 return h(React.Fragment,null,
  h('section',{className:'moduleHead'},h('small',null,'QUALITY & YIELD CONTROL'),h('h1',null,'QC & Wastage'),h('p',null,'Incoming, blend and finished-tea quality checks plus expected vs actual production loss.')),
  msg?h('div',{className:'notice'},msg):null,
  h('div',{className:'twocol'},h('section',{className:'panel'},h(Title,{t:'QC checks',tag:'QUALITY'}),qcTable),h('section',{className:'panel'},h(Title,{t:'Wastage & variance',tag:'YIELD'}),wasteTable))
 );
}


function OperationsWorkspace({user}){
 const[data,setData]=React.useState(null),[territory,setTerritory]=React.useState({territories:[],outlets:[]}),[work,setWork]=React.useState(null),[msg,setMsg]=React.useState(''),[tab,setTab]=React.useState('network');
 async function load(){try{const[a,b,c]=await Promise.all([api('operations.dashboard'),api('franchise.territory'),api('operations.workboard')]);setData(a);setTerritory(b);setWork(c)}catch(e){setMsg('Operations data could not be loaded: '+(e.code||'ERROR'))}}
 React.useEffect(()=>{load()},[]);
 if(!data||!work)return msg?h('div',{className:'authError'},msg):h(Loading);
 const n=data.network||{},s=data.sales||{},pipeline=(data.outlets||[]).filter(x=>!['live','closed'].includes(String(x.pipeline_stage||'')));
 const tabs=[['network','Network'],['tasks','Daily Tasks'],['visits','Field Visits'],['tickets','Support Tickets'],['compliance','Compliance & Training'],['marketing','Marketing'],['communications','Communications']];
 let body=null;
 if(tab==='network'){
  body=h(React.Fragment,null,
   h('div',{className:'stats'},
    h(Card,{t:'Active outlets',v:String(n.active_outlets||0),s:String(n.total_outlets||0)+' total open records'}),
    h(Card,{t:'Pipeline / setup',v:String(n.pipeline_outlets||0),s:String(pipeline.length)+' pipeline records'}),
    h(Card,{t:'30-day / current sales',v:money(s.current||0),s:String(s.receipts||0)+' receipts this month'}),
    h(Card,{t:'Attention outlets',v:String(n.attention_outlets||0),s:'Watch + critical'})
   ),
   h('section',{className:'panel'},h(Title,{t:'Opening pipeline',tag:'LAUNCH TRACKER'}),h(DataTable,{rows:pipeline,cols:[['code','Code'],['name','Outlet'],['district','District'],['pipeline_stage','Stage'],['target_open_date','Target'],['next_action','Next action'],['blocking_reason','Blocker'],['health','Health']],empty:'No outlets are currently in the opening pipeline.'})),
   h('section',{className:'panel'},h(Title,{t:'Territory hierarchy',tag:'DIVISION → DISTRICT → UPAZILA'}),h(DataTable,{rows:territory.territories||[],cols:[['division','Division'],['district','District'],['upazila','Upazila'],['total_outlets','Outlets'],['active_outlets','Active'],['pipeline_outlets','Pipeline'],['attention_outlets','Attention'],['sales_30d','30d sales',money]],empty:'No territory assignments yet. Set Division, District and Upazila from Outlet 360°.'})),
   h('section',{className:'panel'},h(Title,{t:'Outlet operations table',tag:'LIVE'}),h(DataTable,{rows:data.outlets||[],cols:[['code','Code'],['name','Outlet'],['district','District'],['upazila','Upazila'],['status','Status'],['margin_percent','Margin %'],['sales_30d','30d sales',money],['receipts_30d','Receipts'],['stock_value','Stock value',money],['health','Health'],['last_sale_at','Last sale']],empty:'No outlet records yet.'}))
  );
 }else body=h(OperationsPatch2View,{mode:tab,work,onRefresh:load,user});
 return h(React.Fragment,null,
  h('section',{className:'moduleHead'},h('small',null,'NETWORK OPERATIONS · PATCH 2'),h('h1',null,'Franchise & Retail Operations'),h('p',null,'Daily task control, field visits, support tickets, communications, compliance, training and marketing execution with SLA and escalation.')),
  msg?h('div',{className:'authError'},msg):null,
  h('div',{className:'opsTabs'},tabs.map(([k,l])=>h('button',{key:k,className:tab===k?'active':'',onClick:()=>setTab(k)},l))),
  body
 );
}

function OperationsPatch2View({mode,work,onRefresh,user}){
 const[msg,setMsg]=React.useState(''),[busy,setBusy]=React.useState(false);
 const outlets=work.outlets||[],assignees=work.assignees||[];
 const[task,setTask]=React.useState({franchise_id:'',task_type:'follow_up',title:'',detail:'',priority:'medium',assigned_user_id:'',due_at:''});
 const[visit,setVisit]=React.useState({id:0,franchise_id:'',visit_type:'routine',status:'scheduled',scheduled_at:'',visitor_user_id:'',cleanliness_score:100,branding_score:100,product_display_score:100,pricing_compliance_score:100,pos_usage_score:100,stock_handling_score:100,findings:'',corrective_action:'',next_visit_at:''});
 const[ticket,setTicket]=React.useState({franchise_id:'',category:'other',subject:'',detail:'',priority:'medium',assigned_user_id:'',due_at:''});
 const[comm,setComm]=React.useState({franchise_id:'',channel:'call',direction:'outbound',subject:'',note:'',promised_date:'',follow_up_at:''});
 const[comp,setComp]=React.useState({franchise_id:'',branding_score:100,pricing_score:100,pos_usage_score:100,stock_handling_score:100,customer_service_score:100,findings:'',corrective_action:'',corrective_due_at:''});
 const[marketing,setMarketing]=React.useState({id:0,franchise_id:'',campaign_code:'',campaign_name:'',status:'planned',priority:'medium',assigned_user_id:'',due_at:'',start_date:'',end_date:'',assets_ready:'0',execution_verified:'0',sales_before:0,sales_during:0,notes:''});
 async function act(fn,success='Saved'){setBusy(true);setMsg('');try{await fn();setMsg(success);await onRefresh()}catch(e){setMsg(e.code||e.message||'ACTION_FAILED')}finally{setBusy(false)}}
 function outletSelect(value,onChange,label='Outlet'){return h('label',{className:'field'},label,h('select',{value,onChange:e=>onChange(e.target.value)},h('option',{value:''},'Select outlet'),outlets.map(x=>h('option',{key:x.id,value:x.id},x.code+' · '+x.name))))}
 function assigneeSelect(value,onChange,label='Assigned to'){return h('label',{className:'field'},label,h('select',{value,onChange:e=>onChange(e.target.value)},h('option',{value:''},'Unassigned'),assignees.map(x=>h('option',{key:x.id,value:x.id},x.name+' · '+x.role))))}
 function selectField(label,value,onChange,items){return h('label',{className:'field'},label,h('select',{value,onChange:e=>onChange(e.target.value)},items.map(x=>h('option',{key:x,value:x},x.replaceAll('_',' ')))))}
 const metrics=h('div',{className:'stats opsPatch2Stats'},
  h(Card,{t:'Open tasks',v:String(work.metrics?.open_tasks||0),s:String(work.metrics?.overdue_tasks||0)+' overdue'}),
  h(Card,{t:'Open tickets',v:String(work.metrics?.open_tickets||0),s:String(work.metrics?.overdue_tickets||0)+' overdue'}),
  h(Card,{t:'Visits · 7 days',v:String(work.metrics?.visits_next_7d||0),s:'Scheduled follow-up'}),
  h(Card,{t:'Compliance / training',v:String(work.metrics?.open_compliance||0),s:String(work.metrics?.training_attention||0)+' training attention'})
 );

 let body=null;
 if(mode==='tasks'){
  async function createTask(){if(!task.title)return;await act(async()=>{await api('operations.task.create',{method:'POST',body:{...task,franchise_id:task.franchise_id?Number(task.franchise_id):0,assigned_user_id:task.assigned_user_id?Number(task.assigned_user_id):null,due_at:task.due_at||null}});setTask({franchise_id:'',task_type:'follow_up',title:'',detail:'',priority:'medium',assigned_user_id:'',due_at:''})},'Task created')}
  async function updateTask(r,status,escalate=false){await act(()=>api('operations.task.update',{method:'POST',body:{id:Number(r.id),status,escalate}}),escalate?'Task escalated':'Task updated')}
  body=h(React.Fragment,null,metrics,
   h('section',{className:'panel'},h(Title,{t:'Create daily task / follow-up',tag:'SLA CONTROL'}),h('div',{className:'formrow opsForm'},
    outletSelect(task.franchise_id,v=>setTask({...task,franchise_id:v})),
    h(Field,{label:'Task title',value:task.title,onChange:v=>setTask({...task,title:v})}),
    selectField('Type',task.task_type,v=>setTask({...task,task_type:v}),['follow_up','stock','settlement','launch','training','visit','support','marketing','compliance','other']),
    selectField('Priority',task.priority,v=>setTask({...task,priority:v}),['low','medium','high','critical']),
    assigneeSelect(task.assigned_user_id,v=>setTask({...task,assigned_user_id:v})),
    h(Field,{label:'Due at',value:task.due_at,onChange:v=>setTask({...task,due_at:v}),type:'datetime-local'}),
    h(Field,{label:'Detail',value:task.detail,onChange:v=>setTask({...task,detail:v})}),
    h('button',{className:'primary fit',disabled:busy,onClick:createTask},'Create task')
   )),
   h(DataTable,{rows:work.tasks||[],cols:[['outlet','Outlet'],['title','Task'],['task_type','Type'],['priority','Priority'],['assigned_to','Assigned'],['due_at','Due'],['sla_status','SLA'],['status','Status'],['last_update','Last update'],['escalation_level','Esc.'],['actions','Actions',(_,r)=>h('div',{className:'actionRow'},!['done','cancelled'].includes(r.status)?h('button',{className:'miniBtn',onClick:()=>updateTask(r,'in_progress')},'Start'):null,!['done','cancelled'].includes(r.status)?h('button',{className:'miniBtn',onClick:()=>updateTask(r,'done')},'Done'):null,!['done','cancelled'].includes(r.status)?h('button',{className:'miniBtn dangerLite',onClick:()=>updateTask(r,r.status,true)},'Escalate'):null)]],empty:'No operations tasks yet.'})
  );
 }else if(mode==='visits'){
  async function saveVisit(){if(!visit.franchise_id)return;await act(async()=>{await api('operations.visit.save',{method:'POST',body:{...visit,id:Number(visit.id||0),franchise_id:Number(visit.franchise_id),visitor_user_id:visit.visitor_user_id?Number(visit.visitor_user_id):null}});setVisit({id:0,franchise_id:'',visit_type:'routine',status:'scheduled',scheduled_at:'',visitor_user_id:'',cleanliness_score:100,branding_score:100,product_display_score:100,pricing_compliance_score:100,pos_usage_score:100,stock_handling_score:100,findings:'',corrective_action:'',next_visit_at:''})},'Field visit saved')}
  function editVisit(r){setVisit({id:Number(r.id),franchise_id:String(r.franchise_id),visit_type:r.visit_type||'routine',status:r.status||'scheduled',scheduled_at:r.scheduled_at?String(r.scheduled_at).replace(' ','T').slice(0,16):'',visitor_user_id:r.visitor_user_id?String(r.visitor_user_id):'',cleanliness_score:r.cleanliness_score??100,branding_score:r.branding_score??100,product_display_score:r.product_display_score??100,pricing_compliance_score:r.pricing_compliance_score??100,pos_usage_score:r.pos_usage_score??100,stock_handling_score:r.stock_handling_score??100,findings:r.findings||'',corrective_action:r.corrective_action||'',next_visit_at:r.next_visit_at?String(r.next_visit_at).replace(' ','T').slice(0,16):''})}
  body=h(React.Fragment,null,metrics,
   h('section',{className:'panel'},h(Title,{t:visit.id?'Update field visit':'Schedule / record field visit',tag:'INSPECTION'}),h('div',{className:'formrow opsForm'},
    outletSelect(visit.franchise_id,v=>setVisit({...visit,franchise_id:v})),
    h(Field,{label:'Visit type',value:visit.visit_type,onChange:v=>setVisit({...visit,visit_type:v})}),
    selectField('Status',visit.status,v=>setVisit({...visit,status:v}),['scheduled','completed','follow_up','cancelled']),
    assigneeSelect(visit.visitor_user_id,v=>setVisit({...visit,visitor_user_id:v}),'Visitor'),
    h(Field,{label:'Scheduled at',value:visit.scheduled_at,onChange:v=>setVisit({...visit,scheduled_at:v}),type:'datetime-local'}),
    h(Field,{label:'Cleanliness',value:visit.cleanliness_score,onChange:v=>setVisit({...visit,cleanliness_score:v}),type:'number'}),
    h(Field,{label:'Branding',value:visit.branding_score,onChange:v=>setVisit({...visit,branding_score:v}),type:'number'}),
    h(Field,{label:'Display',value:visit.product_display_score,onChange:v=>setVisit({...visit,product_display_score:v}),type:'number'}),
    h(Field,{label:'Pricing',value:visit.pricing_compliance_score,onChange:v=>setVisit({...visit,pricing_compliance_score:v}),type:'number'}),
    h(Field,{label:'POS usage',value:visit.pos_usage_score,onChange:v=>setVisit({...visit,pos_usage_score:v}),type:'number'}),
    h(Field,{label:'Stock handling',value:visit.stock_handling_score,onChange:v=>setVisit({...visit,stock_handling_score:v}),type:'number'}),
    h(Field,{label:'Findings',value:visit.findings,onChange:v=>setVisit({...visit,findings:v})}),
    h(Field,{label:'Corrective action',value:visit.corrective_action,onChange:v=>setVisit({...visit,corrective_action:v})}),
    h(Field,{label:'Next visit',value:visit.next_visit_at,onChange:v=>setVisit({...visit,next_visit_at:v}),type:'datetime-local'}),
    h('button',{className:'primary fit',disabled:busy,onClick:saveVisit},visit.id?'Update visit':'Save visit')
   )),
   h(DataTable,{rows:work.visits||[],cols:[['outlet','Outlet'],['visit_type','Type'],['status','Status'],['scheduled_at','Scheduled'],['visitor','Visitor'],['overall_score','Score'],['next_visit_at','Next'],['findings','Findings'],['actions','Action',(_,r)=>h('button',{className:'miniBtn',onClick:()=>editVisit(r)},'Edit')]],empty:'No field visits recorded.'})
  );
 }else if(mode==='tickets'){
  async function createTicket(){if(!ticket.franchise_id||!ticket.subject)return;await act(async()=>{await api('operations.ticket.create',{method:'POST',body:{...ticket,franchise_id:Number(ticket.franchise_id),assigned_user_id:ticket.assigned_user_id?Number(ticket.assigned_user_id):null,due_at:ticket.due_at||null}});setTicket({franchise_id:'',category:'other',subject:'',detail:'',priority:'medium',assigned_user_id:'',due_at:''})},'Support ticket created')}
  async function updateTicket(r,status,escalate=false){let resolution=null;if(['resolved','closed'].includes(status))resolution=prompt('Resolution note:',r.resolution||'Resolved');await act(()=>api('operations.ticket.update',{method:'POST',body:{id:Number(r.id),status,escalate,resolution:resolution??r.resolution}}),escalate?'Ticket escalated':'Ticket updated')}
  body=h(React.Fragment,null,metrics,
   h('section',{className:'panel'},h(Title,{t:'Open support issue',tag:'SLA'}),h('div',{className:'formrow opsForm'},
    outletSelect(ticket.franchise_id,v=>setTicket({...ticket,franchise_id:v})),
    selectField('Category',ticket.category,v=>setTicket({...ticket,category:v}),['stock','pos','delivery','customer','branding','payment','staff_training','other']),
    h(Field,{label:'Subject',value:ticket.subject,onChange:v=>setTicket({...ticket,subject:v})}),
    selectField('Priority',ticket.priority,v=>setTicket({...ticket,priority:v}),['low','medium','high','critical']),
    assigneeSelect(ticket.assigned_user_id,v=>setTicket({...ticket,assigned_user_id:v})),
    h(Field,{label:'Due at',value:ticket.due_at,onChange:v=>setTicket({...ticket,due_at:v}),type:'datetime-local'}),
    h(Field,{label:'Detail',value:ticket.detail,onChange:v=>setTicket({...ticket,detail:v})}),
    h('button',{className:'primary fit',disabled:busy,onClick:createTicket},'Create ticket')
   )),
   h(DataTable,{rows:work.tickets||[],cols:[['ticket_no','Ticket'],['outlet','Outlet'],['category','Category'],['subject','Subject'],['priority','Priority'],['assigned_to','Assigned'],['due_at','Due'],['sla_status','SLA'],['status','Status'],['escalation_level','Esc.'],['actions','Actions',(_,r)=>h('div',{className:'actionRow'},!['resolved','closed','cancelled'].includes(r.status)?h('button',{className:'miniBtn',onClick:()=>updateTicket(r,'in_progress')},'Start'):null,!['resolved','closed','cancelled'].includes(r.status)?h('button',{className:'miniBtn',onClick:()=>updateTicket(r,'resolved')},'Resolve'):null,!['resolved','closed','cancelled'].includes(r.status)?h('button',{className:'miniBtn dangerLite',onClick:()=>updateTicket(r,r.status,true)},'Escalate'):null)]],empty:'No support tickets yet.'})
  );
 }else if(mode==='communications'){
  async function saveComm(){if(!comm.franchise_id||!comm.note)return;await act(async()=>{await api('operations.communication.create',{method:'POST',body:{...comm,franchise_id:Number(comm.franchise_id),follow_up_at:comm.follow_up_at||null,promised_date:comm.promised_date||null}});setComm({franchise_id:'',channel:'call',direction:'outbound',subject:'',note:'',promised_date:'',follow_up_at:''})},'Communication note saved')}
  body=h(React.Fragment,null,metrics,
   h('section',{className:'panel'},h(Title,{t:'Log call / WhatsApp / meeting note',tag:'FOLLOW-UP TIMELINE'}),h('div',{className:'formrow opsForm'},
    outletSelect(comm.franchise_id,v=>setComm({...comm,franchise_id:v})),
    selectField('Channel',comm.channel,v=>setComm({...comm,channel:v}),['call','whatsapp','email','meeting','visit','internal_note','other']),
    selectField('Direction',comm.direction,v=>setComm({...comm,direction:v}),['outbound','inbound','internal']),
    h(Field,{label:'Subject',value:comm.subject,onChange:v=>setComm({...comm,subject:v})}),
    h(Field,{label:'Note',value:comm.note,onChange:v=>setComm({...comm,note:v})}),
    h(Field,{label:'Promised date',value:comm.promised_date,onChange:v=>setComm({...comm,promised_date:v}),type:'date'}),
    h(Field,{label:'Follow-up at',value:comm.follow_up_at,onChange:v=>setComm({...comm,follow_up_at:v}),type:'datetime-local'}),
    h('button',{className:'primary fit',disabled:busy,onClick:saveComm},'Save note')
   )),
   h(DataTable,{rows:work.communications||[],cols:[['created_at','Date'],['outlet','Outlet'],['channel','Channel'],['direction','Direction'],['subject','Subject'],['note','Note'],['promised_date','Promised'],['follow_up_at','Follow-up'],['created_by_name','Logged by']],empty:'No communication notes yet.'})
  );
 }else if(mode==='compliance'){
  async function saveCompliance(){if(!comp.franchise_id)return;await act(async()=>{await api('operations.compliance.save',{method:'POST',body:{...comp,franchise_id:Number(comp.franchise_id),corrective_due_at:comp.corrective_due_at||null}});setComp({franchise_id:'',branding_score:100,pricing_score:100,pos_usage_score:100,stock_handling_score:100,customer_service_score:100,findings:'',corrective_action:'',corrective_due_at:''})},'Compliance check recorded')}
  async function resolveCompliance(r){await act(()=>api('operations.compliance.resolve',{method:'POST',body:{id:Number(r.id)}}),'Compliance corrective action resolved')}
  body=h(React.Fragment,null,metrics,
   h('div',{className:'twocol'},
    h('section',{className:'panel'},h(Title,{t:'Compliance inspection',tag:'SOP'}),h('div',{className:'formrow opsForm'},
     outletSelect(comp.franchise_id,v=>setComp({...comp,franchise_id:v})),
     h(Field,{label:'Branding',value:comp.branding_score,onChange:v=>setComp({...comp,branding_score:v}),type:'number'}),
     h(Field,{label:'Pricing',value:comp.pricing_score,onChange:v=>setComp({...comp,pricing_score:v}),type:'number'}),
     h(Field,{label:'POS usage',value:comp.pos_usage_score,onChange:v=>setComp({...comp,pos_usage_score:v}),type:'number'}),
     h(Field,{label:'Stock handling',value:comp.stock_handling_score,onChange:v=>setComp({...comp,stock_handling_score:v}),type:'number'}),
     h(Field,{label:'Customer service',value:comp.customer_service_score,onChange:v=>setComp({...comp,customer_service_score:v}),type:'number'}),
     h(Field,{label:'Findings',value:comp.findings,onChange:v=>setComp({...comp,findings:v})}),
     h(Field,{label:'Corrective action',value:comp.corrective_action,onChange:v=>setComp({...comp,corrective_action:v})}),
     h(Field,{label:'Corrective due',value:comp.corrective_due_at,onChange:v=>setComp({...comp,corrective_due_at:v}),type:'datetime-local'}),
     h('button',{className:'primary fit',disabled:busy,onClick:saveCompliance},'Save compliance')
    )),
    h('section',{className:'panel'},h(Title,{t:'Training attention',tag:'PENDING / EXPIRING'}),h(DataTable,{rows:work.training_attention||[],cols:[['outlet','Outlet'],['course_title','Course'],['status','Status'],['scheduled_at','Scheduled'],['expires_at','Expires'],['trainer','Trainer']],empty:'No training attention items.'}))
   ),
   h(DataTable,{rows:work.compliance||[],cols:[['checked_at','Checked'],['outlet','Outlet'],['overall_score','Score'],['status','Status'],['findings','Findings'],['corrective_action','Corrective'],['corrective_due_at','Due'],['resolved_at','Resolved'],['actions','Action',(_,r)=>!r.resolved_at&&r.status!=='compliant'?h('button',{className:'miniBtn',onClick:()=>resolveCompliance(r)},'Resolve'):'—']],empty:'No compliance checks yet.'})
  );
 }else if(mode==='marketing'){
  async function saveMarketing(escalate=false){if(!marketing.franchise_id||!marketing.campaign_name)return;await act(async()=>{await api('operations.marketing.save',{method:'POST',body:{...marketing,id:Number(marketing.id||0),franchise_id:Number(marketing.franchise_id),assigned_user_id:marketing.assigned_user_id?Number(marketing.assigned_user_id):null,assets_ready:marketing.assets_ready==='1',execution_verified:marketing.execution_verified==='1',due_at:marketing.due_at||null,escalate}});if(!escalate)setMarketing({id:0,franchise_id:'',campaign_code:'',campaign_name:'',status:'planned',priority:'medium',assigned_user_id:'',due_at:'',start_date:'',end_date:'',assets_ready:'0',execution_verified:'0',sales_before:0,sales_during:0,notes:''})},escalate?'Campaign escalated':'Marketing execution saved')}
  function editMarketing(r){setMarketing({id:Number(r.id),franchise_id:String(r.franchise_id),campaign_code:r.campaign_code||'',campaign_name:r.campaign_name||'',status:r.status||'planned',priority:r.priority||'medium',assigned_user_id:r.assigned_user_id?String(r.assigned_user_id):'',due_at:r.due_at?String(r.due_at).replace(' ','T').slice(0,16):'',start_date:r.start_date||'',end_date:r.end_date||'',assets_ready:Number(r.assets_ready)?'1':'0',execution_verified:Number(r.execution_verified)?'1':'0',sales_before:r.sales_before||0,sales_during:r.sales_during||0,notes:r.notes||''})}
  async function escalateMarketing(r){await act(()=>api('operations.marketing.save',{method:'POST',body:{id:Number(r.id),franchise_id:Number(r.franchise_id),campaign_code:r.campaign_code||'',campaign_name:r.campaign_name,status:r.status,priority:r.priority||'medium',assigned_user_id:r.assigned_user_id?Number(r.assigned_user_id):null,due_at:r.due_at||null,start_date:r.start_date||null,end_date:r.end_date||null,assets_ready:Number(r.assets_ready)===1,execution_verified:Number(r.execution_verified)===1,sales_before:Number(r.sales_before||0),sales_during:Number(r.sales_during||0),notes:r.notes||'',escalate:true}}),'Campaign escalated')}
  body=h(React.Fragment,null,metrics,
   h('section',{className:'panel'},h(Title,{t:marketing.id?'Update campaign execution':'Track outlet marketing execution',tag:'HQ POLICY · OUTLET EXECUTION'}),h('div',{className:'formrow opsForm'},
    outletSelect(marketing.franchise_id,v=>setMarketing({...marketing,franchise_id:v})),
    h(Field,{label:'Campaign code',value:marketing.campaign_code,onChange:v=>setMarketing({...marketing,campaign_code:v})}),
    h(Field,{label:'Campaign name',value:marketing.campaign_name,onChange:v=>setMarketing({...marketing,campaign_name:v})}),
    selectField('Status',marketing.status,v=>setMarketing({...marketing,status:v}),['planned','ready','live','completed','not_participating']),
    selectField('Priority',marketing.priority,v=>setMarketing({...marketing,priority:v}),['low','medium','high','critical']),
    assigneeSelect(marketing.assigned_user_id,v=>setMarketing({...marketing,assigned_user_id:v})),
    h(Field,{label:'Due at',value:marketing.due_at,onChange:v=>setMarketing({...marketing,due_at:v}),type:'datetime-local'}),
    h(Field,{label:'Start',value:marketing.start_date,onChange:v=>setMarketing({...marketing,start_date:v}),type:'date'}),
    h(Field,{label:'End',value:marketing.end_date,onChange:v=>setMarketing({...marketing,end_date:v}),type:'date'}),
    h('label',{className:'field'},'Assets ready',h('select',{value:marketing.assets_ready,onChange:e=>setMarketing({...marketing,assets_ready:e.target.value})},h('option',{value:'0'},'No'),h('option',{value:'1'},'Yes'))),
    h('label',{className:'field'},'Execution verified',h('select',{value:marketing.execution_verified,onChange:e=>setMarketing({...marketing,execution_verified:e.target.value})},h('option',{value:'0'},'No'),h('option',{value:'1'},'Yes'))),
    h(Field,{label:'Sales before',value:marketing.sales_before,onChange:v=>setMarketing({...marketing,sales_before:v}),type:'number'}),
    h(Field,{label:'Sales during',value:marketing.sales_during,onChange:v=>setMarketing({...marketing,sales_during:v}),type:'number'}),
    h(Field,{label:'Notes',value:marketing.notes,onChange:v=>setMarketing({...marketing,notes:v})}),
    h('button',{className:'primary fit',disabled:busy,onClick:()=>saveMarketing(false)},marketing.id?'Update campaign':'Save campaign')
   )),
   h(DataTable,{rows:work.marketing||[],cols:[['outlet','Outlet'],['campaign_name','Campaign'],['status','Status'],['priority','Priority'],['assigned_to','Assigned'],['due_at','Due'],['sla_status','SLA'],['assets_ready','Assets',v=>Number(v)?'Yes':'No'],['execution_verified','Verified',v=>Number(v)?'Yes':'No'],['sales_during','Sales',money],['escalation_level','Esc.'],['actions','Actions',(_,r)=>h('div',{className:'actionRow'},h('button',{className:'miniBtn',onClick:()=>editMarketing(r)},'Edit'),!['completed','not_participating'].includes(r.status)?h('button',{className:'miniBtn dangerLite',onClick:()=>escalateMarketing(r)},'Escalate'):null)]],empty:'No marketing execution records.'})
  );
 }
 return h(React.Fragment,null,msg?h('div',{className:'notice'},h('b',null,'Operations'),h('span',null,msg)):null,body);
}
function RecordsWorkspace({module,title,desc}){
 const[rows,setRows]=React.useState([]),[msg,setMsg]=React.useState('');
 React.useEffect(()=>{api('workspace.records?module='+encodeURIComponent(module)).then(r=>setRows(r.records||[])).catch(e=>setMsg('This workspace could not be loaded: '+(e.code||'ERROR')))},[module]);
 const keys=rows[0]?Object.keys(rows[0]).filter(k=>k!=='id').slice(0,9):[];
 const cols=keys.map(k=>[k,k.replaceAll('_',' ').replace(/\b\w/g,x=>x.toUpperCase()),v=>/(amount|profit|share|cost|value|paid)/.test(k)?money(v):String(v??'')]);
 return h(React.Fragment,null,
  h('section',{className:'moduleHead'},h('small',null,'TEA SHOP BD BUSINESS OS'),h('h1',null,title),h('p',null,desc)),
  msg?h('div',{className:'notice'},msg):null,
  h(DataTable,{rows,cols,empty:'No records yet. The database module is ready for live entries.'})
 );
}
function SettingsWorkspace({user}){const[settings,setSettings]=React.useState({}),[msg,setMsg]=React.useState('');React.useEffect(()=>{api('settings').then(r=>setSettings(r.settings||{})).catch(()=>setMsg('Settings could not be loaded.'))},[]);const rows=Object.entries(settings).map(([setting_key,setting_value],i)=>({id:i+1,setting_key,setting_value}));return h(React.Fragment,null,h('section',{className:'moduleHead'},h('small',null,'SYSTEM CONTROL'),h('h1',null,'Settings'),h('p',null,'Founder-controlled pricing, tax and performance policy settings. Operational values stay separate from personal identities.')),msg?h('div',{className:'notice'},msg):null,user.role!=='OWNER'?h('div',{className:'notice'},'Read-only settings for this role.'):null,h(DataTable,{rows,cols:[['setting_key','Setting'],['setting_value','Value']],empty:'No settings configured.'}))}
function Reports(){const[data,setData]=React.useState(null),[supplierReport,setSupplierReport]=React.useState([]),[packReport,setPackReport]=React.useState({}),[error,setError]=React.useState('');
React.useEffect(()=>{Promise.all([api('reports.summary'),api('supplier.report'),api('packaging.report')]).then(([a,b,d])=>{setData(a);setSupplierReport(b.report||[]);setPackReport(d||{})}).catch(()=>setError('Reports could not be loaded.'))},[]);
if(!data)return error?h('div',{className:'authError'},error):h(Loading);
const t=data.totals||{},supplierDue=supplierReport.reduce((a,b)=>a+Number(b.balance||0),0),packPurchase=Number(packReport.total_purchases||0),aged90=supplierReport.reduce((a,b)=>a+Number(b.d90_plus||0),0);
return h(React.Fragment,null,
h('section',{className:'moduleHead'},h('small',null,'MANAGEMENT REPORTS'),h('h1',null,'Reports & control center'),h('p',null,'Sales, tea procurement, packaging procurement, supplier payable, blending, stock and outlet performance.')),
h('div',{className:'stats'},h(Card,{t:'Verified sales',v:money(t.sales),s:(t.receipts||0)+' receipts'}),h(Card,{t:'Raw tea purchase',v:money(t.purchases),s:Number(t.raw_kg||0).toFixed(2)+' kg received'}),h(Card,{t:'Packaging purchase',v:money(packPurchase),s:money(packReport.stock_value||0)+' stock value'}),h(Card,{t:'Supplier outstanding',v:money(supplierDue),s:money(aged90)+' aged 90+'})),
h('div',{className:'stats'},h(Card,{t:'QC-passed production',v:Number(t.production_kg||0).toFixed(2)+' kg',s:Number(t.wastage_kg||0).toFixed(2)+' kg wastage'}),h(Card,{t:'Packaging consumed',v:money(packReport.consumed_value||0),s:'Actual BOM usage'}),h(Card,{t:'Franchise earned margin',v:money(t.earned_margin),s:'Verified eligible sales'}),h(Card,{t:'Active outlets',v:String(t.active_outlets||0),s:'Franchise network'})),
h('div',{className:'twocol'},
h('section',{className:'panel'},h(Title,{t:'All supplier aging',tag:'PAYABLE'}),h(DataTable,{rows:supplierReport,cols:[['name','Supplier'],['supplier_type','Type'],['purchases','Purchase',money],['payments','Paid',money],['balance','Due',money],['d0_30','0–30',money],['d31_60','31–60',money],['d61_90','61–90',money],['d90_plus','90+',money]],empty:'No supplier data yet.'})),
h('section',{className:'panel'},h(Title,{t:'Packaging material stock',tag:'VALUE + REORDER'}),h(DataTable,{rows:packReport.materials||[],cols:[['name','Material'],['unit','Unit'],['stock_qty','Stock'],['avg_rate','Avg rate',money],['stock_value','Value',money],['low_stock','Low?',v=>Number(v)?'YES':'No']],empty:'No packaging material data yet.'}))),
h('div',{className:'twocol'},
h('section',{className:'panel'},h(Title,{t:'Monthly sales',tag:'12 MONTHS'}),h(DataTable,{rows:data.monthly||[],cols:[['period','Period'],['receipts','Receipts'],['sales','Sales',money],['margin','Earned margin',money]],empty:'No sales yet.'})),
h('section',{className:'panel'},h(Title,{t:'Outlet performance',tag:'TOP 20'}),h(DataTable,{rows:data.top_outlets||[],cols:[['outlet','Outlet'],['receipts','Receipts'],['sales','Sales',money],['margin','Margin',money]],empty:'No outlets yet.'}))),
h('section',{className:'moduleHead compactHead'},h('small',null,'PACKAGING SUPPLIERS'),h('h1',null,'Packaging supplier purchase report')),
h(DataTable,{rows:packReport.suppliers||[],cols:[['supplier','Supplier'],['receipts','GRNs'],['purchases','Purchase',money]],empty:'No packaging supplier purchases yet.'}))}
function Users({user}){const[rows,setRows]=React.useState([]),[roles,setRoles]=React.useState([]),[auditRows,setAuditRows]=React.useState([]),[name,setName]=React.useState(''),[email,setEmail]=React.useState(''),[role,setRole]=React.useState('OPERATIONS'),[busy,setBusy]=React.useState(false),[msg,setMsg]=React.useState('');
async function loadAll(){try{const[a,b,d]=await Promise.all([api('users'),api('roles'),api('audit')]);setRows(a.users||[]);setRoles(b.roles||[]);setAuditRows(d.audit||[])}catch{setMsg('User or audit data could not be loaded.')}}
React.useEffect(()=>{loadAll()},[]);
async function createUser(){if(!name||!email)return;setBusy(true);setMsg('');try{const r=await api('user.create',{method:'POST',body:{name,email,role}});setMsg('ONE-TIME TEMP PASSWORD for '+email+': '+r.temporary_password+' — copy it now and share privately.');setName('');setEmail('');await loadAll()}catch(e){setMsg('User create failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
async function toggle(u){setBusy(true);setMsg('');try{await api('user.toggle',{method:'POST',body:{id:Number(u.id),active:Number(u.active)?0:1}});await loadAll()}catch(e){setMsg('Status update failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
async function reset(u){if(!confirm('Reset password for '+u.email+'?'))return;setBusy(true);setMsg('');try{const r=await api('user.reset',{method:'POST',body:{id:Number(u.id)}});setMsg('ONE-TIME RESET PASSWORD for '+u.email+': '+r.temporary_password+' — copy it now and share privately.');await loadAll()}catch(e){setMsg('Password reset failed: '+(e.code||'ERROR'))}finally{setBusy(false)}}
return h(React.Fragment,null,
h('section',{className:'moduleHead'},h('small',null,'RBAC & AUDIT'),h('h1',null,'Users & access'),h('p',null,'Founder-controlled accounts, password reset, activation state and recent audit trail.')),
msg?h('div',{className:'credentialNotice'},msg):null,
h('section',{className:'panel formrow'},h(Field,{label:'Full name',value:name,onChange:setName}),h(Field,{label:'Email',value:email,onChange:setEmail,type:'email'}),h('label',{className:'field'},'Role',h('select',{value:role,onChange:e=>setRole(e.target.value)},roles.map(r=>h('option',{key:r.code,value:r.code},r.name)))),h('button',{className:'primary fit',disabled:busy,onClick:createUser},busy?'Working…':'Create user')),
rows.length?h('div',{className:'tablewrap panel'},h('table',null,h('thead',null,h('tr',null,['User','Email','Role','Active','Password','Actions'].map(x=>h('th',{key:x},x)))),h('tbody',null,rows.map(u=>h('tr',{key:u.id},h('td',null,h('b',null,u.name)),h('td',null,u.email),h('td',null,u.role_name),h('td',null,Number(u.active)?'Yes':'No'),h('td',null,Number(u.must_change_password)?'Change required':'Set'),h('td',null,h('div',{className:'actionRow'},Number(u.id)!==Number(user.id)?h('button',{className:'miniBtn',disabled:busy,onClick:()=>toggle(u)},Number(u.active)?'Disable':'Enable'):null,h('button',{className:'miniBtn',disabled:busy,onClick:()=>reset(u)},'Reset password')))))))):h(Empty,{text:'No users found.'}),
h('section',{className:'moduleHead compactHead'},h('small',null,'RECENT ACTIVITY'),h('h1',null,'Audit trail')),
h(DataTable,{rows:auditRows,cols:[['created_at','Time'],['user_name','User'],['action','Action'],['entity_type','Entity'],['entity_id','ID'],['ip_address','IP']],empty:'No audit activity yet.'}))}
function Crud({title,desc,rows,fields,onAdd,cols}){const[form,setForm]=React.useState(Object.fromEntries(fields.map(x=>[x[0],'' ])));function add(){onAdd(form);setForm(Object.fromEntries(fields.map(x=>[x[0],'' ])))}return h(React.Fragment,null,h('section',{className:'moduleHead'},h('small',null,'OPERATIONS'),h('h1',null,title),h('p',null,desc)),h('section',{className:'panel formrow'},fields.map(([k,l,t])=>h(Field,{key:k,label:l,value:form[k],type:t||'text',onChange:v=>setForm({...form,[k]:v})})),h('button',{className:'primary fit',onClick:add},'Add record')),h(DataTable,{rows,cols,empty:'No records yet.'}))}
function DataTable({rows,cols,empty}){if(!rows.length)return h(Empty,{text:empty});return h('div',{className:'tablewrap panel'},h('table',null,h('thead',null,h('tr',null,cols.map(c=>h('th',{key:c[0]},c[1])))),h('tbody',null,rows.map((r,i)=>h('tr',{key:r.id||i},cols.map(c=>h('td',{key:c[0]},c[2]?c[2](r[c[0]],r):String(r[c[0]]??''))))))))}
ReactDOM.createRoot(document.getElementById('root')).render(h(Shell));
})();