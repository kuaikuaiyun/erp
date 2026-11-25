<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantDomainStore;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StoreEmailPurchaseReminderController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
    ) {}

    public function __invoke(Request $request, AssistantDomainStore $domainStore): RedirectResponse|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->assertAssistantPortal($context['portal']);
        $this->authorization->ensureCanViewShopifyAnalytics($context['user']);

        abort_unless(
            $this->authorization->scopeDomainStores(AssistantDomainStore::query(), $context['user'])
                ->whereKey($domainStore->getKey())
                ->exists(),
            403
        );

        $data = $request->validate([
            'exempt' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $exempt = (bool) $data['exempt'];
        $reason = trim((string) ($data['reason'] ?? ''));

        $domainStore->forceFill([
            'email_purchase_reminder_exempt' => $exempt,
            'email_purchase_reminder_exempt_reason' => $exempt && $reason !== '' ? $reason : null,
            'email_purchase_reminder_exempted_by' => $exempt ? $context['user']->getKey() : null,
            'email_purchase_reminder_exempted_at' => $exempt ? now() : null,
        ])->save();

        return back()->with('success', $exempt
            ? '该店铺已设为无需购买邮箱，后续不再显示未购买提醒。'
            : '已恢复该店铺的邮箱购买提醒。');
    }
}
