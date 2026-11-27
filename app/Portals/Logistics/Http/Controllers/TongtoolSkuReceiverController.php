<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Portals\Logistics\TongtoolSource\Receiver;
use App\Portals\Logistics\TongtoolSource\SourceResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Throwable;

final class TongtoolSkuReceiverController
{
    public function __invoke(Request $request, Receiver $receiver): JsonResponse
    {
        $hash = (string) config('logistics.tongtool_receiver.writer_hash');
        $token = (string) $request->bearerToken();
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)
            || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $token)
            || !hash_equals($hash, hash('sha256', $token))) {
            return $this->result(['status' => 'unavailable', 'errorCode' => 'admin_unauthorized'], 401);
        }

        $action = $request->query('action');
        if (!is_string($action) || !in_array($action, ['admin_status', 'admin_import', 'admin_alias_import',
            'admin_pcl_import', 'admin_conflict', 'admin_defer'], true)) {
            return $this->result(['status' => 'unavailable', 'errorCode' => 'unknown_action'], 404);
        }
        if (!$request->isMethod($action === 'admin_status' ? 'GET' : 'POST')) {
            return $this->result(['status' => 'unavailable', 'errorCode' => 'method_not_allowed'], 405);
        }

        try {
            $body = [];
            if ($request->isMethod('POST')) {
                $content = $request->getContent();
                if (strlen($content) > 2_000_000) {
                    return $this->result(['status' => 'unavailable', 'errorCode' => 'invalid_import'], 413);
                }
                $body = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($body)) {
                    return $this->result(['status' => 'unavailable', 'errorCode' => 'invalid_import'], 400);
                }
            }

            return $this->result($receiver->handle($action, $body));
        } catch (JsonException) {
            return $this->result(['status' => 'unavailable', 'errorCode' => 'invalid_import'], 400);
        } catch (SourceResponse $exception) {
            return $this->result($exception->body, $exception->status);
        } catch (Throwable $exception) {
            report($exception);

            return $this->result(['status' => 'unavailable', 'errorCode' => 'database_busy'], 503);
        }
    }

    private function result(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store']);
    }
}
