(function(){
function t(i,v){const e=document.getElementById(i);if(e)e.textContent=v}
function w(i,p){const e=document.getElementById(i);if(e)e.style.width=p+'%'}
function s(x){return String(x??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function a(x){return s(x).replace(/"/g,'&quot;').replace(/'/g,'&#39;')}
function modeClass(mode){
  const m=String(mode||'').toUpperCase()
  if(m.includes('TGIF')) return 'mode-tgif'
  if(m.includes('YSF')) return 'mode-ysf'
  if(m.includes('D-STAR') || m.includes('DSTAR')) return 'mode-dstar'
  if(m.includes('P25')) return 'mode-p25'
  if(m.includes('NXDN')) return 'mode-nxdn'
  if(m.includes('BM') || m.includes('BRAND')) return 'mode-bm'
  return 'mode-other'
}
function modeCell(r){
  const mode=String(r.mode||'--')
  return `<span class="mode-badge ${modeClass(mode)}">${s(mode)}</span>`
}
function callsignCell(r){
  const raw=String(r.callsign??'--')
  const display=String(r.callsign_display||raw||'--')
  const id=String(r.dmr_id||(/^\d+$/.test(raw)?raw:''))
  const title=id?`DMR ID ${id}`:display
  const sub=(id && display!==id)?`<span class="dmr-id">${s(id)}</span>`:''
  if(r.qrz_url){return `<a class="qrz-link" href="${a(r.qrz_url)}" target="_blank" rel="noopener noreferrer" title="${a(title)}">${s(display)}<span class="qrz-open">↗</span></a>${sub}`}
  return `<span class="station-plain">${s(display)}</span>`
}
function targetCell(r){
  return `<span class="target-text ${modeClass(r.mode)}">${s(r.target||'--')}</span>`
}
function qualityCell(r){
  const loss=String(r.loss??'').trim()
  const ber=String(r.ber??'').trim()
  const hasLoss=loss && loss!=='--' && loss!=='-'
  const hasBer=ber && ber!=='--' && ber!=='-'
  if(hasLoss || hasBer){
    const bits=[]
    if(hasLoss) bits.push(`Loss ${s(loss)}`)
    if(hasBer) bits.push(`BER ${s(ber)}`)
    return `<span class="quality-good">${bits.join(' / ')}</span>`
  }
  return '<span class="quality-muted">Available when logged</span>'
}
function durationCell(r){
  const dur=String(r.dur??'--').trim()
  const hasDur=dur && dur!=='--' && dur!=='-'
  if(hasDur) return `<span class="duration-value">${s(dur)}</span>`
  const mode=String(r.mode||'').toUpperCase()
  const src=String(r.src||'').toUpperCase()
  if(mode.includes('TGIF') && src==='NET'){
    return '<span class="duration-event" title="TGIF receive event logged; backend did not expose RX duration">RX</span>'
  }
  return '<span class="duration-muted">--</span>'
}
function table(id,rows){const b=document.querySelector('#'+id+' tbody');if(!b)return;b.innerHTML=!Array.isArray(rows)||!rows.length?'<tr><td colspan="7">No activity available</td></tr>':rows.map(r=>`<tr class="activity-row ${modeClass(r.mode)}"><td>${s(r.time||'--')}</td><td>${modeCell(r)}</td><td>${callsignCell(r)}</td><td>${targetCell(r)}</td><td>${s(r.src||'--')}</td><td class="duration-cell">${durationCell(r)}</td><td>${qualityCell(r)}</td></tr>`).join('')}
function recent(lines){const box=document.getElementById('recent-log');if(!box)return;box.innerHTML=!Array.isArray(lines)||!lines.length?'<div>No recent events</div>':lines.map(line=>{const c=/warning|timeout|failed|not found/i.test(line)?'warn':(/login success|Begin TX|mode ->/i.test(line)?'active':'');return `<div class="${c}">${s(line)}</div>`}).join('')}
function stateLabel(provider, wanted, connectionState){return provider===wanted && connectionState==='Connected' ? 'Active' : 'Idle'}
function apply(d){
 t('hero-title',`${d.call||'--'} / RPT ${d.rpt||'--'} / GW ${d.gw||'--'}`)
 t('hero-network',d.network||'--')
 t('hero-target',d.target_display||'--')
 t('hero-last-heard',d.last_heard||'--')
 t('hero-path',d.path_label||'--')
 t('node-rpt',d.rpt||'--')
 t('node-call',d.call||'--')
 t('node-status-text',d.connection_state||'--')
 t('node-provider',d.provider||'--')
 t('node-tscc',`TS ${d.ts||'--'} / CC ${d.cc||'--'}`)
 t('left-gw',d.gw||'--')
 t('left-target',d.target_display||'--')
 t('left-last-tune',d.last_tune||'--')
 t('left-mute',d.mute||'--')
 t('left-source',d.source_file||'--')
 t('left-status-label',d.left_status_label||'Last Tune')
 t('summary-line-1',`${d.network||'Unknown'} ${d.connection_state||'Unknown'}`)
 t('summary-line-2',d.target_note||'(runtime detection active)')
 t('mode-bm',stateLabel(d.provider,'BrandMeister',d.connection_state))
 t('mode-tgif',stateLabel(d.provider,'TGIF/HBLink',d.connection_state))
 t('mode-ysf',stateLabel(d.provider,'YSF',d.connection_state))
 t('mode-dstar',stateLabel(d.provider,'D-Star',d.connection_state))
 t('mode-p25',stateLabel(d.provider,'P25',d.connection_state))
 t('mode-nxdn',stateLabel(d.provider,'NXDN',d.connection_state))
 t('footer-ab-version',d.ab_version||'--')
 t('footer-mute',d.mute||'--')
 t('footer-last-tune',d.last_heard||d.last_tune||'--')
 t('footer-usrp-rx',d.usrp_rx_port||'--')
 t('footer-usrp-tx',d.usrp_tx_port||'--')
 t('logs-path',d.log_source||'/var/log/dvswitch/Analog_Bridge.log')
 const rx=parseFloat(d.usrp_rx_gain||0), tx=parseFloat(d.usrp_tx_gain||0)
 w('meter-rx-gain',Math.max(10,Math.min(100,Math.round(rx*25))))
 w('meter-tx-gain',Math.max(10,Math.min(100,Math.round(tx*100))))
 w('meter-usrp',60)
 w('meter-dv3000',46)
 w('node-mini-meter',d.connection_state==='Connected' ? (d.provider==='BrandMeister'?72:(d.provider==='TGIF/HBLink'?58:48)) : 32)
 t('meter-rx-text',`RX Gain ${rx||0}`)
 t('meter-tx-text',`TX Gain ${tx||0}`)
 t('meter-usrp-text',`USRP ${d.usrp_rx_port||'--'} / ${d.usrp_tx_port||'--'}`)
 t('meter-dv3000-text',`${d.vocoder_label||'Vocoder'} ${d.vocoder_status||'--'}`)
 table('gateway-table',d.gateway_activity||[])
 table('local-table',d.local_activity||[])
 recent(d.recent_events||[])
 const status=document.getElementById('action-status')
 if(status && d.service_control_verified && !status.dataset.locked){
   status.className='action-status ok'
   status.textContent='Service control verified'
 }
}
async function refresh(){try{const r=await fetch('api/runtime_status.php?_='+Date.now(),{cache:'no-store'});if(r.status===401){let loc='login.php';try{const j=await r.json();if(j&&j.login)loc=j.login}catch(e){}window.location.href=loc.startsWith('/')?loc:'login.php';return}if(!r.ok)throw new Error('runtime status unavailable');apply(await r.json())}catch(e){t('hero-title','Runtime status load failed');t('summary-line-1','Could not read live runtime sources');t('summary-line-2','(check api/runtime_status.php)')}}
async function serviceAction(btn){
  const action=btn.getAttribute('data-service-action')
  const service=btn.getAttribute('data-service-name')
  const status=document.getElementById('action-status')
  try{
    btn.disabled=true
    if(status){
      status.dataset.locked='1'
      status.className='action-status'
      status.textContent=`Working: ${action} ${service}...`
    }
    const body=new URLSearchParams()
    body.set('action',action)
    body.set('service',service)
    const r=await fetch('api/service_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-DVSwitch-Cockpit':'service-action'},body:body.toString()})
    if(r.status===401){let loc='login.php';try{const j=await r.json();if(j&&j.login)loc=j.login}catch(e){}window.location.href=loc.startsWith('/')?loc:'login.php';return}
    const d=await r.json()
    if(!r.ok||!d.ok)throw new Error(d.error||'Service action failed')
    if(status){
      status.className='action-status ok'
      status.textContent=d.message||'Action completed.'
    }
    setTimeout(async ()=>{
      await refresh()
      if(status) status.dataset.locked=''
    },1200)
  }catch(e){
    if(status){
      status.className='action-status err'
      status.textContent=e.message||'Action failed.'
      status.dataset.locked=''
    }
  }finally{
    btn.disabled=false
  }
}

function versionParts(v){
  return String(v||'0.0.0').trim().split('.').map(x=>parseInt(x.replace(/[^0-9].*$/,''),10)||0)
}
function isRemoteVersionNewer(local,remote){
  const l=versionParts(local), r=versionParts(remote)
  const n=Math.max(l.length,r.length,3)
  for(let i=0;i<n;i++){
    const a=l[i]||0, b=r[i]||0
    if(b>a)return true
    if(b<a)return false
  }
  return false
}
async function checkForUpdate(){
  const bolt=document.getElementById('update-bolt')
  if(!bolt)return
  const local=(bolt.getAttribute('data-current-version')||'').trim()
  if(!local)return
  const controller=window.AbortController?new AbortController():null
  const timer=controller?setTimeout(()=>controller.abort(),3500):null
  try{
    const url='https://raw.githubusercontent.com/TerryClaiborne/dvswitch-cockpit/main/VERSION?_='+Date.now()
    const r=await fetch(url,{cache:'no-store',signal:controller?controller.signal:undefined})
    if(!r.ok)throw new Error('version check unavailable')
    const remote=(await r.text()).trim()
    if(isRemoteVersionNewer(local,remote)){
      bolt.classList.add('update-available')
      bolt.title=`Update available: ${local} → ${remote}`
      bolt.setAttribute('aria-label',`DVSwitch Cockpit update available: ${local} to ${remote}`)
    }else{
      bolt.classList.remove('update-available')
      bolt.title='DVSwitch Cockpit is up to date'
    }
  }catch(e){
    bolt.classList.remove('update-available')
    bolt.title=''
  }finally{
    if(timer)clearTimeout(timer)
  }
}

document.addEventListener('DOMContentLoaded',function(){
  refresh()
  checkForUpdate()
  setInterval(refresh,5000)
  document.querySelectorAll('[data-service-action]').forEach(b=>b.addEventListener('click',()=>serviceAction(b)))
})
})();


(function(){
  function refreshRibbon(root){
    const endpoint = root.getAttribute('data-endpoint');
    if (!endpoint) return;

    fetch(endpoint, {cache: 'no-store'})
      .then(function(res){
        if (!res.ok) throw new Error('Ribbon status unavailable');
        return res.json();
      })
      .then(function(data){
        Object.keys(data || {}).forEach(function(key){
          const node = root.querySelector('[data-k="' + key + '"]');
          if (node) node.textContent = data[key];
        });
      })
      .catch(function(){});
  }

  function initSystemRibbon(){
    document.querySelectorAll('.dvc-ribbon[data-endpoint]').forEach(function(root){
      if (root.dataset.ribbonInit === '1') return;
      root.dataset.ribbonInit = '1';
      refreshRibbon(root);
      setInterval(function(){ refreshRibbon(root); }, 5000);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSystemRibbon);
  } else {
    initSystemRibbon();
  }
})();

