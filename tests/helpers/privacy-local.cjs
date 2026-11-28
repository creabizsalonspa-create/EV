const { execFileSync } = require("node:child_process");
const assert = require("node:assert/strict");
async function withPrivacyFixture(run) {
  const php = String.raw`
 require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
 $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if (!app()->environment(['local','testing']) || Illuminate\Support\Facades\DB::getDriverName()!=='sqlite') throw new RuntimeException('Isolated local privacy fixture only.');
 $run=bin2hex(random_bytes(8));$accounts=[];
 foreach(['customer','admin','other','copycustomer'] as $role){
  $password=bin2hex(random_bytes(24)).'Aa9!';
  $user=App\Models\User::factory()->create(['name'=>'Synthetic privacy '.$role,'email'=>'privacy-'.$role.'-'.$run.'@example.invalid','email_verified_at'=>now(),'password'=>Illuminate\Support\Facades\Hash::make($password),'role'=>$role==='admin'?'admin':'customer']);
  $accounts[$role]=['id'=>$user->id,'email'=>$user->email,'password'=>$password];
 }
 $copyOwner=App\Models\User::findOrFail($accounts['copycustomer']['id']);
 $case=App\Models\AccountPrivacyRequest::create(['user_id'=>$copyOwner->id,'kind'=>'access','status'=>'reviewing','request_details_encrypted'=>Illuminate\Support\Facades\Crypt::encryptString('Synthetic native access-copy fixture only.')]);
 $admin=App\Models\User::findOrFail($accounts['admin']['id']);
 $request=Illuminate\Http\Request::create('/synthetic-local-privacy-copy','POST',['password'=>$accounts['admin']['password'],'request_key'=>'synthetic-copy-'.$run]);
 $request->setUserResolver(fn()=>$admin);
 $response=app(App\Http\Controllers\PrivacyAccessExportController::class)->prepare($request,$case,app(App\Services\Privacy\AccountDataExport::class));
 if ($response->getStatusCode()!==201 || $case->fresh()->status!=='closed_review') throw new RuntimeException('Synthetic authorized copy preparation failed.');
 echo json_encode(['copy_request_id'=>$case->id,'fixture_only'=>true,'base'=>'http://127.0.0.1:8000','run'=>$run,'accounts'=>$accounts]);
 `;
  const container = process.env.EV_PRIVACY_PHP_CONTAINER;
  assert(!container || /^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/.test(container));
  const base = new URL(
    process.env.EV_PRIVACY_FIXTURE_BASE || "http://127.0.0.1:8000",
  );
  assert(
    base.protocol === "http:" &&
      base.hostname === "127.0.0.1" &&
      !base.username &&
      !base.password &&
      base.pathname === "/" &&
      !base.search &&
      !base.hash,
  );
  const fixture = JSON.parse(
    execFileSync(
      container ? "docker" : "./scripts/php",
      container ? ["exec", container, "php", "-r", php] : ["-r", php],
      {
        encoding: "utf8",
        stdio: ["ignore", "pipe", "pipe"],
      },
    ),
  );
  assert(fixture.fixture_only && fixture.base === "http://127.0.0.1:8000");
  fixture.base = base.origin;
  return run(fixture);
}
module.exports = { withPrivacyFixture };
