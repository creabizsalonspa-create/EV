<?php
require __DIR__.'/../services/business-api/app/Services/Discovery/OpenChargeMapProjection.php';
use App\Services\Discovery\OpenChargeMapProjection;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$poi = [
    'ID' => 12345,
    'AddressInfo' => ['Title'=>'Test charger <script>alert(1)</script>', 'AddressLine1'=>'Fixture road', 'Town'=>'Dubai', 'Country'=>['ISOCode'=>'AE'], 'Latitude'=>25.2,'Longitude'=>55.3],
    'DataProvider' => ['Title'=>'Test OCM contributor', 'License'=>'CC BY-SA 4.0', 'IsOpenDataLicensed'=>true, 'ApiKey'=>'never disclose'],
    'OperatorInfo'=>['Title'=>'Crowd-supplied operator', 'ConnectionKey'=>'never disclose'],
    'Connections'=>[['ConnectionType'=>['Title'=>'CCS2'],'PowerKW'=>120,'AccessToken'=>'secret'],['ConnectionType'=>['Title'=>'Type 2'], 'PowerKW'=>'NaN']],
    'SubmissionStatusTypeID'=>200,
    'StatusType'=>['IsOperational'=>true],
    'UsageType'=>['Title'=>'Membership Required','IsMembershipRequired'=>true,'IsAccessKeyRequired'=>false],
    'UsageCost'=>'Check operator pricing <b>unverified</b>',
    'DateLastStatusUpdate'=>'2026-11-07T10:00:00Z',
    'PrivateNotes'=>'untrusted',
];
$now = new DateTimeImmutable('2026-11-07T12:00:00Z');
$v=OpenChargeMapProjection::item($poi,$now);
check(is_array($v),'valid licensed item refused');
check($v['id']==='ocm:12345','id mapping');
check($v['source_age_minutes']===120,'clock age');
check($v['source_clock_evidence']==='CROWD_REPORTED_TIME','clock evidence');
check($v['data_license']==='CC BY-SA 4.0','licensing');
check($v['source_url']==='https://openchargemap.org/site/poi/details/12345','fixed origin');
check($v['availability']==='unknown' && $v['remote_control']===false && $v['reservable']===false && $v['billing_eligible']===false,'never actionable');
check($v['reported_operational']===true && $v['reported_operational_evidence']==='OPEN_CHARGE_MAP_CROWD_STATUS_UNVERIFIED','crowd operational evidence');
check($v['reported_access']==='Membership Required' && $v['reported_membership_required']===true && $v['reported_access_key_required']===false, 'source-qualified access metadata');
check($v['reported_access_evidence']==='OPEN_CHARGE_MAP_CROWD_ACCESS_UNVERIFIED','unverified access provenance');
check($v['reported_cost_note']==='Check operator pricing <b>unverified</b>' && $v['reported_cost_evidence']==='OPEN_CHARGE_MAP_CROWD_PRICE_UNVERIFIED','crowd cost provenance');
$badCost=$poi;$badCost['UsageCost']=str_repeat('x',500);check(strlen(OpenChargeMapProjection::item($badCost,$now)['reported_cost_note'])===240,'cost length capped');
$badCost=$poi;$badCost['UsageCost']=['private'=>'ignored'];check(OpenChargeMapProjection::item($badCost,$now)['reported_cost_note']==='','malformed cost hidden');
check(count($v['connections'])===2 && $v['connections'][1]['reported_power_kw']===null,'reported connections');
check(!str_contains(json_encode($v),'ApiKey')&&!str_contains(json_encode($v),'secret')&&!str_contains(json_encode($v),'PrivateNotes'),'private data leakage');
$bad=$poi;$bad['StatusType']['IsOperational']=false;check(OpenChargeMapProjection::item($bad,$now)['reported_operational']===false,'reported false');
$bad=$poi;$bad['StatusType']['IsOperational']='true';check(OpenChargeMapProjection::item($bad,$now)['reported_operational']===null,'malformed status unknown');
$bad=$poi;$bad['DataProvider']['IsOpenDataLicensed']=false;check(OpenChargeMapProjection::item($bad,$now)===null,'not open licensed');
$bad=$poi;$bad['DataProvider']['License']='';check(OpenChargeMapProjection::item($bad,$now)===null,'unattributed license');
$bad=$poi;$bad['AddressInfo']['Longitude']=181;check(OpenChargeMapProjection::item($bad,$now)===null,'invalid coords');
$bad=$poi;$bad['SubmissionStatusTypeID']=100;check(OpenChargeMapProjection::item($bad,$now)===null,'unpublished data');
$bad=$poi;$bad['DateLastStatusUpdate']='2026-11-09T10:00:00Z';$future=OpenChargeMapProjection::item($bad,$now);check($future['source_age_minutes']===null && $future['source_clock_evidence']==='SOURCE_CLOCK_AHEAD','future clock');
$bad=$poi;$bad['DateLastStatusUpdate']='tomorrow';$unknown=OpenChargeMapProjection::item($bad,$now);check($unknown['source_age_minutes']===null && $unknown['source_clock_evidence']==='SOURCE_TIME_UNKNOWN','invalid source clock');
$bad=$poi;$bad['ID']=0;check(OpenChargeMapProjection::item($bad,$now)===null,'id bounds');
$bad=$poi;$bad['DataProvider']['DataProviderStatusType']['IsProviderEnabled']=false;check(OpenChargeMapProjection::item($bad,$now)===null,'disabled provider');
$bad=$poi;$bad['Connections']=array_fill(0,100,['ConnectionType'=>['Title'=>'Type 2'],'PowerKW'=>22]);check(count(OpenChargeMapProjection::item($bad,$now)['connections'])===20,'connection cap');
echo 'PASS standalone licensed OCM projection/denied-capability assertions'.PHP_EOL;
