<?php

namespace App\Http\Controllers;

use App\Services\DingTalk\CallbackCrypto;
use App\Services\DingTalk\MembershipService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DingTalkEventController extends Controller
{
    public function __invoke(Request $request, CallbackCrypto $crypto, MembershipService $memberships)
    {
        try {
            $company = app(\App\Services\DingTalk\CompanyService::class)->enabled($request->integer('company'));
            $crypto = $crypto->forCompany($company);
            $signature = $request->query('msg_signature', $request->query('signature'));
            $timestamp = $request->query('timeStamp', $request->query('timestamp'));
            $nonce = $request->query('nonce');
            $encrypted = $request->input('encrypt');
            foreach ([$signature, $timestamp, $nonce, $encrypted] as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 1048576) {
                    throw new HttpException(403, __('dingtalk.errors.bad_callback'));
                }
            }
            if (strlen($signature) !== 40 || !ctype_digit($timestamp) || strlen($timestamp) > 16 || strlen($nonce) > 256) {
                throw new HttpException(403, __('dingtalk.errors.bad_callback'));
            }
            // Signed retries may be delayed. Deduplicate event IDs rather than rejecting a valid old delivery.
            $message = $crypto->decrypt($signature, $timestamp, $nonce, $encrypted);
            $event = json_decode($message, true);
            if (!is_array($event)) {
                throw new HttpException(403, __('dingtalk.errors.bad_callback'));
            }
            if (($event['EventType'] ?? '') !== 'check_url') {
                $memberships->receiveEvent($event, $company);
            }
            return response()->json($crypto->encrypt('success'));
        } catch (HttpException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }
        // Database failures are not acknowledged: DingTalk must be able to retry the delivery.
    }
}
