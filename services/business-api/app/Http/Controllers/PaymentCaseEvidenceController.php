<?php

namespace App\Http\Controllers;

use App\Models\Finance\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Internal collaboration on canonical gateway cases; never provider submission. */
class PaymentCaseEvidenceController extends Controller
{
    private function access(Request $r, int $case, bool $lock = false): object
    {
        $hint = DB::table('gateway_cases')->find($case);
        abort_unless($hint, 404);
        // Match the canonical resolution lock order: invoice, then case.
        $invoice = Invoice::whereKey($hint->invoice_id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        abort_unless($r->user()->role === 'admin' || $invoice->user_id === $r->user()->id || ($r->user()->role === 'vendor' && $invoice->vendor_id === $r->user()->id), 404);

        return DB::table('gateway_cases')->where('id', $case)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    private function safe(object $row): array
    {
        return collect((array) $row)->only(['id', 'case_id', 'uploaded_by', 'kind', 'mime_type', 'size_bytes', 'sha256', 'created_at'])->all() + ['evidence_status' => 'PRIVATE_REVIEW_ONLY', 'provider_submitted' => false];
    }

    public function index(Request $r, int $case)
    {
        $source = $this->access($r, $case);
        $page = DB::table('gateway_case_evidence')->where('case_id', $case)->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->safe($row));

        return response()->json($page->toArray() + ['can_upload' => $source->status === 'open'])->header('Cache-Control', 'no-store, private');
    }

    public function upload(Request $r, int $case)
    {
        $this->access($r, $case);
        $v = $r->validate(['kind' => 'required|in:service_record,receipt,correspondence,other', 'consent' => 'required|accepted', 'idempotency_key' => 'required|string|max:100', 'document' => 'required|file|mimetypes:application/pdf,image/jpeg,image/png|max:4096']);
        $file = $r->file('document');
        $content = $file->getContent();
        $mime = $file->getMimeType();
        // MIME sniffing plus a bounded signature; the private viewer is sandboxed.
        abort_unless(match ($mime) {
            'application/pdf' => str_starts_with($content, '%PDF-'),
            'image/png' => str_starts_with($content, "\x89PNG\r\n\x1a\n"),
            'image/jpeg' => str_starts_with($content, "\xff\xd8\xff"),
            default => false,
        }, 422, 'Unsupported document signature.');
        $sha = hash('sha256', $content);
        $key = hash('sha256', $r->user()->id.':'.$case.':'.$v['idempotency_key']);
        $fingerprint = hash('sha256', json_encode([$case, $v['kind'], $mime, strlen($content), $sha], JSON_THROW_ON_ERROR));
        $path = 'payment-case-evidence/'.Str::uuid().'.enc';
        try {
            $row = DB::transaction(function () use ($r, $case, $v, $content, $mime, $sha, $key, $fingerprint, $path) {
                $source = $this->access($r, $case, true);
                if ($old = DB::table('gateway_case_evidence')->where('request_key', $key)->first()) {
                    abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Evidence request payload changed.');

                    return $old;
                }
                abort_unless($source->status === 'open', 409, 'Resolved cases cannot receive new evidence.');
                abort_if(DB::table('gateway_case_evidence')->where('case_id', $case)->count() >= 20, 409, 'Case evidence limit reached.');
                abort_unless(Storage::disk('local')->put($path, Crypt::encryptString($content)), 503, 'Private evidence storage unavailable.');
                $id = DB::table('gateway_case_evidence')->insertGetId(['case_id' => $case, 'uploaded_by' => $r->user()->id, 'kind' => $v['kind'], 'mime_type' => $mime, 'size_bytes' => strlen($content), 'sha256' => $sha, 'storage_path' => $path, 'request_key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'finance.case.private_evidence_uploaded', 'subject' => (string) $id, 'created_at' => now()]);

                return DB::table('gateway_case_evidence')->find($id);
            }, 3);

            return response()->json($this->safe($row), 201)->header('Cache-Control', 'no-store, private');
        } catch (Throwable $e) {
            // Preserve ciphertext on an unknown DB commit outcome. Never destroy
            // a possibly committed evidence object because the connection failed.
            try {
                if (! DB::table('gateway_case_evidence')->where('storage_path', $path)->exists()) {
                    Storage::disk('local')->delete($path);
                }
            } catch (Throwable) {
                // Encrypted orphan retention is safer than deleting audit evidence.
            }
            throw $e;
        }
    }

    public function download(Request $r, int $case, int $evidence)
    {
        $this->access($r, $case);
        $row = DB::table('gateway_case_evidence')->where('case_id', $case)->where('id', $evidence)->first();
        abort_unless($row, 404);
        $cipher = Storage::disk('local')->get($row->storage_path);
        abort_unless(is_string($cipher), 503);
        try {
            $content = Crypt::decryptString($cipher);
        } catch (Throwable) {
            abort(503, 'Private evidence integrity check failed.');
        }
        abort_unless(strlen($content) === (int) $row->size_bytes && hash_equals($row->sha256, hash('sha256', $content)), 503, 'Private evidence integrity check failed.');
        $extension = match ($row->mime_type) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', default => 'pdf'
        };

        return response($content, 200, ['Content-Type' => $row->mime_type, 'Content-Disposition' => 'attachment; filename="case-evidence-'.$row->id.'.'.$extension.'"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'Cache-Control' => 'no-store, private']);
    }
}
