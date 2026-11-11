const { execFileSync } = require("node:child_process");
const assert = require("node:assert/strict");
async function withPrivacyFixture(run) {
  const php = String.raw`
 require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
 $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if (!app()->environment(['local','testing']) || Illuminate\Support\Facades\DB::getDriverName()!=='sqlite') throw new RuntimeException('Isolated local privacy fixture only.');
 $run=bin2hex(random_bytes(8));$accounts=[];
 foreach(['customer','admin','other'] as $role){
  $password=bin2hex(random_bytes(24)).'Aa9!';
  $user=App\Models\User::factory()->create(['name'=>'Synthetic privacy '.$role,'email'=>'privacy-'.$role.'-'.$run.'@example.invalid','email_verified_at'=>now(),'password'=>Illuminate\Support\Facades\Hash::make($password),'role'=>$role==='admin'?'admin':'customer']);
  $accounts[$role]=['email'=>$user->email,'password'=>$password];
 }
 echo json_encode(['fixture_only'=>true,'base'=>'http://127.0.0.1:8000','run'=>$run,'accounts'=>$accounts]);
 `;
  const fixture = JSON.parse(
    execFileSync("./scripts/php", ["-r", php], {
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    }),
  );
  assert(fixture.fixture_only && fixture.base === "http://127.0.0.1:8000");
  return run(fixture);
}
module.exports = { withPrivacyFixture };
