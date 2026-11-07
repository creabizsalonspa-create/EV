<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

ob_start();
require __DIR__.'/charger-diagnostics.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$admin = User::create(['name' => 'Synthetic firmware reviewer', 'email' => 'firmware-admin-'.$run.'@example.test', 'password' => $password]);
$admin->role = 'admin';
$admin->save();
$root = storage_path('app/private/firmware/catalog');
File::ensureDirectoryExists($root, 0700);
$key = openssl_pkey_new(['private_key_bits' => 2048]);
$csr = openssl_csr_new(['commonName' => 'SYNTHETIC NOT FOR HARDWARE '.$run], $key);
$certificate = openssl_csr_sign($csr, null, $key, 2);
openssl_x509_export($certificate, $pem);
$bytes = 'SYNTHETIC-NONEXECUTABLE-FIRMWARE-'.$run;
openssl_sign($bytes, $signature, $key, OPENSSL_ALGO_SHA256);
$pin = openssl_x509_fingerprint($pem, 'sha256');
file_put_contents($root.'/'.$run.'.bin', $bytes, LOCK_EX);
file_put_contents($root.'/'.$run.'.pem', $pem, LOCK_EX);
chmod($root.'/'.$run.'.bin', 0600);
chmod($root.'/'.$run.'.pem', 0600);
$entry = ['key' => 'synthetic-'.$run, 'version' => 'sim-'.$run, 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'sha256' => hash('sha256', $bytes), 'file' => $run.'.bin', 'certificate' => $run.'.pem', 'signature' => base64_encode($signature)];
$entries = is_file($root.'/catalog.json') ? json_decode(file_get_contents($root.'/catalog.json'), true, flags: JSON_THROW_ON_ERROR) : [];
$entries[] = $entry;
file_put_contents($root.'/catalog.json', json_encode($entries, JSON_THROW_ON_ERROR), LOCK_EX);
chmod($root.'/catalog.json', 0600);
$env = base_path('.env');
$text = file_get_contents($env);
$pins = array_unique([...config('csms.firmware_signer_pins', []), $pin]);
$value = implode(',', $pins);
$text = preg_match('/^CSMS_FIRMWARE_SIGNER_PINS=/m', $text) ? preg_replace('/^CSMS_FIRMWARE_SIGNER_PINS=.*$/m', 'CSMS_FIRMWARE_SIGNER_PINS='.$value, $text) : $text."\nCSMS_FIRMWARE_SIGNER_PINS=".$value."\n";
file_put_contents($env, $text, LOCK_EX);
chmod($env, 0600);
echo json_encode([...$f, 'catalog_key' => $entry['key'], 'version' => $entry['version'], 'sha256' => $entry['sha256'], 'signer_fingerprint' => $pin, 'fixture_only' => true], JSON_THROW_ON_ERROR);
