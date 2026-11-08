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
$unicode=$poi;$unicode['OperatorInfo']=['Title'=>'Énergie Express'];
$unicode['Connections'][0]['ConnectionType']['Title']='Type É';
$unicodeResult=(new OpenChargeMapAdapter(fn()=>[$unicode]))->search(25.2,55.3,5,'synthetic-secret');
$unicodeFilter=(new OpenChargeMapAdapter())->filterReported($unicodeResult,20,'type é','éNERGIE');
verify($unicodeFilter['count']===1,'Unicode literal casefold across connector and operator');
$regexLiteral=(new OpenChargeMapAdapter())->filterReported($unicodeResult,null,null,'.*');
verify($regexLiteral['count']===0,'escaped regex-like operator remains literal');
$source=(new OpenChargeMapAdapter(fn()=>[$poi]))->search(25.2,55.3,5,'synthetic-secret');
$cachedFiltered=(new OpenChargeMapAdapter())->filterReported($source,20,'Type 2');
verify($cachedFiltered['count']===1 && $source['count']===1,'pure cache filter does not mutate base discovery');
$badCache=$source;$badCache['remote_control']=true;
try { (new OpenChargeMapAdapter())->filterReported($badCache,20,'Type 2'); throw new RuntimeException('unsafe cache was accepted'); }
catch(LookupFailure $e) { verify($e->reason==='invalid_response','unsafe cached capability rejected'); }
// Cached source must never be able to upgrade a discovery-only station
// or connector to live control, booking or payment authority.
foreach ([
    ['availability', 'available'],
    ['remote_control', true],
    ['reservable', true],
    ['billing_eligible', true],
    ['source_status_evidence', 'LIVE_OPERATOR'],
    ['operator_evidence', 'VERIFIED_OPERATOR'],
    ['reported_operational_evidence', 'LIVE_CONNECTOR_STATUS'],
    ['reported_access_evidence', 'VERIFIED_ACCESS'],
    ['reported_cost_evidence', 'VERIFIED_TARIFF'],
    ['distance_basis', 'road_distance'],
    ['data_license', ''],
    ['id', 'ocm:0'],
    ['id', 'ocm:99999999999'],
    ['source_url', 'https://example.test/phishing'],
    ['source_url', 'https://openchargemap.org/site/poi/details/999'],
    ['attribution', 'Open Charge Map / Different provider'],
] as [$field, $value]) {
    $tampered = $source;
    $tampered['results'][0][$field] = $value;
    try { (new OpenChargeMapAdapter())->filterReported($tampered); throw new RuntimeException('unsafe station cache accepted: '.$field); }
    catch (LookupFailure $e) { verify($e->reason === 'invalid_response', 'station cache fail closed: '.$field); }
}
foreach ([
    ['availability', 'available'],
    ['remote_control', true],
    ['reservable', true],
    ['billing_eligible', true],
] as [$field, $value]) {
    $tampered = $source;
    $tampered['results'][0]['connections'][0][$field] = $value;
    try { (new OpenChargeMapAdapter())->filterReported($tampered); throw new RuntimeException('unsafe connector cache accepted: '.$field); }
    catch (LookupFailure $e) { verify($e->reason === 'invalid_response', 'connector cache fail closed: '.$field); }
}
$malformed = $source;
$malformed['results'][0]['connections'] = ['unsafe' => ['availability' => 'unknown']];
try { (new OpenChargeMapAdapter())->filterReported($malformed); throw new RuntimeException('associative connector cache accepted'); }
catch (LookupFailure $e) { verify($e->reason === 'invalid_response', 'reject associative connector cache'); }
// Cached entries are never a source of new public fields or private
// metadata, including nested connection data and response-envelope fields.
$poisoned = $source;
$poisoned['internal_credentials'] = 'TOP_LEVEL_DO_NOT_LEAK';
$poisoned['results'][0]['raw_provider_payload'] = 'ITEM_DO_NOT_LEAK';
$poisoned['results'][0]['connections'][0]['private_connector_token'] = 'CONNECTOR_DO_NOT_LEAK';
$redacted = (new OpenChargeMapAdapter())->filterReported($poisoned);
verify($redacted['count'] === 1, 'safe station kept after removing extraneous cache fields');
$redactedBody = json_encode($redacted, JSON_THROW_ON_ERROR);
foreach (['TOP_LEVEL_DO_NOT_LEAK', 'ITEM_DO_NOT_LEAK', 'CONNECTOR_DO_NOT_LEAK'] as $secret) {
    verify(strpos($redactedBody, $secret) === false, 'unknown cached field never reaches public API');
}
verify($redacted['results'][0]['data_license'] === 'CC BY-SA 4.0', 'source attribution survives cache allowlist');
verify($redacted['results'][0]['connections'][0]['reported_connector_type'] === 'Type 2', 'approved connector survives cache allowlist');
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
