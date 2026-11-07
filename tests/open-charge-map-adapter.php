<?php
require __DIR__.'/../services/business-api/app/Services/Discovery/LookupFailure.php';
require __DIR__.'/../services/business-api/app/Services/Discovery/OpenChargeMapProjection.php';
require __DIR__.'/../services/business-api/app/Services/Discovery/OpenChargeMapAdapter.php';
use App\Services\Discovery\OpenChargeMapAdapter;
use App\Services\Discovery\LookupFailure;
function verify(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
$poi = [
    'ID'=>11, 'DataProvider'=>['IsOpenDataLicensed'=>true,'License'=>'CC BY-SA 4.0','Title'=>'Example open data provider'],
    'AddressInfo'=>['Latitude'=>25.2,'Longitude'=>55.3,'Title'=>'Fixture location','Country'=>['ISOCode'=>'AE']],
    'Connections'=>[['ConnectionType'=>['Title'=>'Type 2'],'PowerKW'=>22]],
    'SubmissionStatusTypeID'=>200,
];
$captured=null;
$transport = function(array $params) use (&$captured,$poi) {
  $captured=$params;
  $second=$poi;$second['ID']=12;$second['AddressInfo']['Latitude']=25.201;
  $far=$poi;$far['ID']=13;$far['AddressInfo']['Latitude']=32;
  $notLicensed=$poi;$notLicensed['ID']=14;$notLicensed['DataProvider']['License']='';
  return [$far,$second,$notLicensed,$poi,$poi];
};
$result=(new OpenChargeMapAdapter($transport))->search(25.2,55.3,3,'synthetic-secret');
verify($result['count']===2,'dedupe and radius filter');
$powerMatch=(new OpenChargeMapAdapter(fn()=>[$poi]))->search(25.2,55.3,5,'synthetic-secret',null,20,'TYPE 2');
verify($powerMatch['count']===1,'min power and connector same record');
$powerMiss=(new OpenChargeMapAdapter(fn()=>[$poi]))->search(25.2,55.3,5,'synthetic-secret',null,50,'TYPE 2');
verify($powerMiss['count']===0,'low-power connector excluded');
verify($result['results'][0]['id']==='ocm:11','sort by distance');
verify($result['results'][1]['id']==='ocm:12','second source');
verify($captured['opendata']==='true' && $captured['distanceunit']==='km' && $captured['maxresults']===25,'bounded OCM request');
$countryPoint = $poi;$countryPoint['AddressInfo']['Country']=['ISOCode'=>'AE'];
$countryMatch=(new OpenChargeMapAdapter(fn($params)=>[$countryPoint]))->search(25.2,55.3,5,'synthetic-secret','AE');
verify($countryMatch['count']===1,'country-match source');
$countryWrong=(new OpenChargeMapAdapter(fn($params)=>[$countryPoint]))->search(25.2,55.3,5,'synthetic-secret','US');
verify($countryWrong['count']===0,'country mismatch not displayed');
verify(!array_key_exists('key',$captured),'no API key in url');
verify($result['availability']==='unknown' && !$result['remote_control'] && !$result['reservable'] && !$result['billing_eligible'],'never actionable');
verify(strpos(json_encode($result),'synthetic-secret')===false,'never display key');
foreach([[90.01,0,5],[25.2,181,5],[25.2,55.3,201],[INF,55.3,5],[25.2,55.3,0]] as [$lat,$lng,$km]) {
    try { (new OpenChargeMapAdapter($transport))->search($lat,$lng,$km,'secret'); throw new RuntimeException('unbounded source query accepted'); }
    catch(LookupFailure $e) { verify($e->status===422,'bad range return code'); }
}
try { (new OpenChargeMapAdapter(fn()=>['bad associative'=>'response']))->search(25.2,55.3,5,'key'); throw new RuntimeException('unvalidated response accepted'); }
catch(LookupFailure $e) { verify($e->reason==='invalid_response','fail closed on payload'); }
$dateline=$poi;$dateline['ID']=22;$dateline['AddressInfo']['Latitude']=0.0;$dateline['AddressInfo']['Longitude']=-179.999;
$res=(new OpenChargeMapAdapter(fn()=>[$dateline]))->search(0,179.999,5,'fake');
verify($res['count']===1,'antimeridian distance');
echo 'PASS OCM offline adapter/transport/privacy/proximity assertions'.PHP_EOL;
