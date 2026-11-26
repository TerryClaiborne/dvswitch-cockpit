<?php
declare(strict_types=1);

function dc_ipsc3d_read_state(string $path): array {
    $out=[]; if(!is_readable($path)) return $out;
    foreach(file($path, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
        if(!str_contains($line,'=')) continue; [$k,$v]=array_map('trim',explode('=',$line,2)); if($k!=='')$out[strtolower($k)]=trim($v,"\"' ");
    }
    return $out;
}
function dc_ipsc3d_ini_value(string $path,string $section,string $key): string {
    if(!is_readable($path))return ''; $current='';
    foreach(file($path, FILE_IGNORE_NEW_LINES)?:[] as $line){
        $line=trim(preg_replace('/[;#].*$/','',(string)$line)); if($line==='')continue;
        if(preg_match('/^\[(.+)\]$/',$line,$m)){$current=strtolower(trim($m[1]));continue;}
        if($current!==strtolower($section))continue;
        if(preg_match('/^'.preg_quote($key,'/').'\s*=\s*(.+)$/i',$line,$m))return trim((string)$m[1],"\"' ");
    }
    return '';
}
function dc_ipsc3d_pid_alive(): bool {
    return dc_process_running(['/var/www/html/alltune2/ipsc3/bin/ipsc3d-arm64','/var/www/html/alltune2/ipsc3/bin/ipsc3d-amd64']);
}
function dc_ipsc3d_log_stamp(string $line,string $tzName): array {
    if(!preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2})\s+/',$line,$m))return ['epoch'=>0,'utc'=>'','display'=>'--'];
    try{$local=new DateTimeImmutable($m[1],new DateTimeZone($tzName));$utc=$local->setTimezone(new DateTimeZone('UTC'));return ['epoch'=>$local->getTimestamp(),'utc'=>$utc->format('Y-m-d H:i:s'),'display'=>$local->format('H:i:s M d')];}catch(Throwable $e){return ['epoch'=>0,'utc'=>'','display'=>'--'];}
}
function dc_ipsc3d_rows_from_log(string $logPath,string $target,string $tzName): array {
    if(!is_readable($logPath))return []; $lines=file($logPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if(!is_array($lines))return [];$lines=array_slice($lines,-900);
    $rows=[];$openRx=null;$openTx=null;
    foreach($lines as $line){$stamp=dc_ipsc3d_log_stamp($line,$tzName);if(($stamp['epoch']??0)<=0)continue;
        if(preg_match('/IPSC3 RX BEGIN\s+src=([0-9]+)\s+dst=([0-9]+)\s+slot=([12])\s+private=([01])\s+server=(\S+)/i',$line,$m)){
            $displayTarget=$target!==''?'TG '.$target:'TG '.$m[2]; $row=dc_make_row((string)$stamp['utc'],(string)$stamp['display'],'DMR/IPSC3',$m[1],$displayTarget,'Net','RX');$row['identity_confidence']='ipsc3d_rx_src';$row['ipsc3_server']=$m[5];$row['ipsc3_slot']='TS'.$m[3];$rows[]=$row;$openRx=count($rows)-1;continue;}
        if(preg_match('/IPSC3 RX END\s+frames=([0-9]+)\s+duration=([0-9.]+)/i',$line,$m)){if($openRx!==null&&isset($rows[$openRx])){$rows[$openRx]['dur']=number_format((float)$m[2],1,'.','');$openRx=null;}continue;}
        if(preg_match('/IPSC3 TX BEGIN\s+src=([0-9]+)\s+dst=([0-9]+)\s+slot=([12])\s+private=([01])\s+server=(\S+)/i',$line,$m)){
            $displayTarget=$target!==''?'TG '.$target:'TG '.$m[2];$row=dc_make_row((string)$stamp['utc'],(string)$stamp['display'],'DMR/IPSC3',$m[1],$displayTarget,'LNet','--');$row['identity_confidence']='ipsc3d_tx_src';$row['ipsc3_server']=$m[5];$row['ipsc3_slot']='TS'.$m[3];$rows[]=$row;$openTx=count($rows)-1;continue;}
        if(preg_match('/IPSC3 TX END\s+frames=([0-9]+)\s+duration=([0-9.]+)/i',$line,$m)){if($openTx!==null&&isset($rows[$openTx])){$rows[$openTx]['dur']=number_format((float)$m[2],1,'.','');$openTx=null;}continue;}
    }
    usort($rows,fn($a,$b)=>strcmp((string)($b['utc']??''),(string)($a['utc']??'')));return array_slice($rows,0,40);
}
function dc_adapter_ipsc3d(string $tzName): array {
    $appDir='/var/www/html/alltune2';$stateFile=$appDir.'/run/alltune2-ipsc3d.state';$cfg=$appDir.'/ipsc3/config/ipsc3d.ini';$logFile=$appDir.'/logs/ipsc3d.log';
    $state=dc_ipsc3d_read_state($stateFile);$activeFlag=strtolower((string)($state['active']??'false'))==='true';$target=preg_replace('/\D+/','',(string)($state['target']??''))??'';$pidAlive=dc_ipsc3d_pid_alive();
    $server=dc_ipsc3d_ini_value($cfg,'ipsc3','server');if($server==='')$server='us';$slot=dc_ipsc3d_ini_value($cfg,'ipsc3','default_slot');if(!in_array($slot,['1','2'],true))$slot='1';
    $rows=dc_ipsc3d_rows_from_log($logFile,$target,$tzName);$lastHeard='--';$lastSignal=0;if($rows){$lastHeard=(string)($rows[0]['callsign_display']??$rows[0]['callsign']??'--');try{$lastSignal=(new DateTime((string)($rows[0]['utc']??''),new DateTimeZone('UTC')))->getTimestamp();}catch(Throwable $e){}}
    if(!$activeFlag||!$pidAlive||$target===''){$idle=dc_idle_adapter('IPSC3');$idle['adapter']='ipsc3d';$idle['provider']='IPSC3';$idle['network']='IPSC3';$idle['rows']=$rows;$idle['last_heard']=$lastHeard;$idle['left_label']='Last Heard';$idle['left_value']=$lastHeard;$idle['signal_epoch']=$lastSignal;return $idle;}
    if($lastSignal<=0)$lastSignal=time();$display='TG '.$target.' / TS'.$slot.' / '.strtoupper($server);
    return ['adapter'=>'ipsc3d','provider'=>'IPSC3','network'=>'IPSC3','connection_state'=>'Connected','path_label'=>'IPSC3D + TLV','target_display'=>$display,'target_note'=>'(from AllTune2 IPSC3D runtime; activity identity from IPSC3D native log)','last_heard'=>$lastHeard,'rows'=>$rows,'left_label'=>'Current Target','left_value'=>$display,'signal_epoch'=>$lastSignal];
}
?>
