<?php

namespace Webkul\Automation\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Webkul\Automation\Models\AutomationWebhook;
use Webkul\Automation\Models\AutomationWebhookDeliveryProxy;
use Webkul\Automation\Models\AutomationWebhookProxy;

class WebhooksController extends Controller
{
    /**
     * GET /admin/automation/webhooks
     */
    public function index(): View
    {
        $webhooks = AutomationWebhookProxy::modelClass()::query()
            ->orderByDesc('id')
            ->paginate(25);

        $recentDeliveries = AutomationWebhookDeliveryProxy::modelClass()::query()
            ->with('webhook')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('automation::admin.webhooks.index', [
            'webhooks' => $webhooks,
            'recentDeliveries' => $recentDeliveries,
            'eventOptions' => AutomationWebhook::EVENTS,
            'plaintextSecret' => session('automation.created_webhook_secret'),
            'createdName' => session('automation.created_webhook_name'),
        ]);
    }

    /**
     * POST /admin/automation/webhooks
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'target_url' => ['required', 'url', 'max:2048'],
            'event' => ['required', 'string', 'in:'.implode(',', AutomationWebhook::EVENTS)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $secret = bin2hex(random_bytes(32));

        $admin = auth('admin')->user();

        AutomationWebhookProxy::modelClass()::create([
            'admin_id' => $admin->id,
            'name' => $data['name'],
            'target_url' => $data['target_url'],
            'event' => $data['event'],
            'secret' => $secret,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()
            ->route('admin.automation.webhooks.index')
            ->with('automation.created_webhook_secret', $secret)
            ->with('automation.created_webhook_name', $data['name']);
    }

    /**
     * POST /admin/automation/webhooks/{id}/toggle
     */
    public function toggle(int $id): RedirectResponse
    {
        $row = AutomationWebhookProxy::modelClass()::find($id);

        if (! $row) {
            return back()->withErrors(['webhook' => "Webhook {$id} not found."]);
        }

        $row->forceFill(['is_active' => ! $row->is_active])->save();

        return back()->with(
            'success',
            "Webhook \"{$row->name}\" is now ".($row->is_active ? 'active' : 'inactive').'.',
        );
    }

    /**
     * DELETE /admin/automation/webhooks/{id}
     */
    public function destroy(int $id): RedirectResponse
    {
        $row = AutomationWebhookProxy::modelClass()::find($id);

        if (! $row) {
            return back()->withErrors(['webhook' => "Webhook {$id} not found."]);
        }

        $name = $row->name;
        $row->delete();

        return back()->with('success', "Webhook \"{$name}\" deleted (along with its delivery history).");
    }
}
