<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalImport;
use App\Portals\Logistics\PayPalImportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PayPalImportController extends Controller
{
    public function __construct(private readonly PayPalImportService $imports)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $maximumBytes = max(1024, (int) config('logistics.paypal.import_max_bytes', 20 * 1024 * 1024));
        if ($rawBody === '' || strlen($rawBody) > $maximumBytes) {
            return response()->json(['accepted' => false, 'message' => '上传文件为空或超过服务器允许大小。'], 413);
        }

        if (($authentication = $this->authenticate($request, $rawBody)) !== null) {
            return $authentication;
        }

        $json = $rawBody;
        if (strtolower((string) $request->header('Content-Encoding')) === 'gzip') {
            $json = gzdecode($rawBody, $maximumBytes * 4 + 1);
            if ($json === false) {
                return response()->json(['accepted' => false, 'message' => '上传的 GZIP 文件无法解压。'], 422);
            }
        }
        if (strlen($json) > $maximumBytes * 4) {
            return response()->json(['accepted' => false, 'message' => '解压后的 JSON 文件超过服务器允许大小。'], 413);
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return response()->json(['accepted' => false, 'message' => 'JSON 格式无效：'.$exception->getMessage()], 422);
        }
        if (!is_array($payload)) {
            return response()->json(['accepted' => false, 'message' => 'JSON 顶层必须是对象。'], 422);
        }

        $validator = Validator::make($payload, [
            'schema_version' => ['required', 'integer', 'in:2'],
            'upload_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'merchant_id' => ['required', 'string', 'max:100'],
            'environment' => ['nullable', 'string', 'in:live,sandbox'],
            'generated_at' => ['nullable', 'date'],
            'period' => ['nullable', 'array'],
            'period.from' => ['nullable', 'date'],
            'period.to' => ['nullable', 'date'],
            'source_responses' => ['required', 'array'],
            'source_responses.transaction_search' => ['required', 'array'],
            'source_responses.transaction_search.segments' => ['present', 'array'],
            'source_responses.dispute_search' => ['required', 'array'],
            'source_responses.dispute_search.list_responses' => ['present', 'array'],
            'source_responses.dispute_search.detail_responses' => ['present', 'array'],
            'collection_summary' => ['required', 'array'],
            'collection_summary.transactions' => ['required', 'integer', 'min:0'],
            'collection_summary.disputes' => ['required', 'integer', 'min:0'],
            'collection_summary.errors' => ['required', 'integer', 'min:0'],
            'errors' => ['present', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'accepted' => false,
                'message' => 'JSON 字段校验失败。',
                'errors' => $validator->errors(),
            ], 422);
        }

        $merchantId = mb_strtoupper(trim((string) $payload['merchant_id']));
        $account = LogisticsPayPalAccount::query()
            ->whereRaw('UPPER(merchant_id) = ?', [$merchantId])
            ->first();
        if (!$account) {
            return response()->json([
                'accepted' => false,
                'message' => 'ERP 中不存在 Merchant ID '.$merchantId.' 对应的 PayPal 账号。',
            ], 422);
        }
        if (!$account->is_enabled) {
            return response()->json([
                'accepted' => false,
                'message' => 'Merchant ID '.$merchantId.' 对应的 PayPal 账号已停用。',
            ], 409);
        }

        $uploadId = trim((string) $payload['upload_id']);
        $checksum = hash('sha256', $json);
        $existing = LogisticsPayPalImport::query()->where('upload_id', $uploadId)->first();
        if ($existing) {
            if (!hash_equals((string) $existing->payload_sha256, $checksum)) {
                return response()->json(['accepted' => false, 'message' => '相同 upload_id 对应了不同文件内容。'], 409);
            }

            return response()->json([
                'accepted' => true,
                'duplicate' => true,
                'upload_id' => $uploadId,
                'status' => $existing->status,
                'message' => '该文件已经接收，无需重复导入。',
            ]);
        }

        $sourceFile = basename(trim((string) $request->header('X-PayPal-Filename', $uploadId.'.json.gz')));
        $storagePath = sprintf(
            'paypal-imports/%s/%s/%s.json',
            now()->format('Y/m'),
            preg_replace('/[^A-Za-z0-9_-]/', '_', $merchantId),
            preg_replace('/[^A-Za-z0-9_-]/', '_', $uploadId),
        );
        if (!Storage::disk('local')->put($storagePath, $json)) {
            return response()->json(['accepted' => false, 'message' => 'ERP 无法保存上传文件。'], 500);
        }

        $period = is_array($payload['period'] ?? null) ? $payload['period'] : [];
        $import = LogisticsPayPalImport::query()->create([
            'logistics_paypal_account_id' => $account->getKey(),
            'upload_id' => $uploadId,
            'merchant_id' => $merchantId,
            'environment' => $payload['environment'] ?? null,
            'source_file' => Str::limit($sourceFile, 255, ''),
            'payload_sha256' => $checksum,
            'storage_path' => $storagePath,
            'period_from' => $this->dateTime($period['from'] ?? null),
            'period_to' => $this->dateTime($period['to'] ?? null),
            'generated_at' => $this->dateTime($payload['generated_at'] ?? null),
            'status' => 'processing',
        ]);
        $account->forceFill([
            'last_import_status' => 'processing',
            'last_import_message' => '正在处理 Windows VPS 上传的 PayPal 数据文件。',
        ])->save();

        try {
            $summary = $this->imports->import($account, $import, $payload);
        } catch (\Throwable $exception) {
            $message = Str::limit('文件导入失败：'.$exception->getMessage(), 1800);
            $import->forceFill(['status' => 'failed', 'error_count' => 1, 'message' => $message])->save();
            $account->forceFill(['last_import_status' => 'failed', 'last_import_message' => $message])->save();

            return response()->json([
                'accepted' => false,
                'upload_id' => $uploadId,
                'status' => 'failed',
                'message' => $message,
            ], 500);
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => false,
            'upload_id' => $uploadId,
            ...$summary,
        ]);
    }

    private function authenticate(Request $request, string $rawBody): ?JsonResponse
    {
        $secret = (string) config('logistics.paypal.import_secret', '');
        if ($secret === '') {
            return response()->json(['accepted' => false, 'message' => 'ERP 尚未配置 PayPal 文件接收密钥。'], 503);
        }

        $timestamp = trim((string) $request->header('X-PayPal-Timestamp'));
        $nonce = trim((string) $request->header('X-PayPal-Nonce'));
        $signature = strtolower(trim((string) $request->header('X-PayPal-Signature')));
        if (!ctype_digit($timestamp) || !preg_match('/^[A-Za-z0-9-]{16,128}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return response()->json(['accepted' => false, 'message' => '文件接收签名请求头不完整。'], 401);
        }

        $clockSkew = max(60, (int) config('logistics.paypal.import_clock_skew_seconds', 300));
        if (abs(time() - (int) $timestamp) > $clockSkew) {
            return response()->json(['accepted' => false, 'message' => '请求时间已过期，请同步 Windows VPS 系统时间。'], 401);
        }

        $message = $timestamp."\n".$nonce."\n".hash('sha256', $rawBody);
        $expected = hash_hmac('sha256', $message, $secret);
        if (!hash_equals($expected, $signature)) {
            return response()->json(['accepted' => false, 'message' => '文件接收签名验证失败。'], 401);
        }

        return null;
    }

    private function dateTime(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
