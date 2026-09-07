<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\CustomFieldRequest;
use App\Models\CustomField;
use App\Models\Subscriber;
use App\Support\ActivityLogger;
use App\Support\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CustomFieldController extends Controller
{
    public function index(Request $request): View
    {
        return view('custom-fields.index', [
            'fields' => CustomField::orderBy('sort_order')->orderBy('name')->get(),
            'allowed' => PlanLimits::for($request->user()->account)->allows('allow_custom_fields'),
        ]);
    }

    public function store(CustomFieldRequest $request): RedirectResponse
    {
        PlanLimits::for($request->user()->account)
            ->ensureFeature('allow_custom_fields', 'custom subscriber fields');

        $field = CustomField::create($request->validated());

        ActivityLogger::log('custom_field.created', "Added custom field {$field->name}", [], $field);

        return to_route('custom-fields.index')->with('success', "Field \"{$field->name}\" added.");
    }

    public function update(CustomFieldRequest $request, CustomField $customField): RedirectResponse
    {
        $originalKey = $customField->key;
        $data = $request->validated();

        DB::transaction(function () use ($customField, $data, $originalKey) {
            $customField->update($data);

            // Renaming the key would orphan every stored value, so the existing
            // values are moved across in the same transaction.
            if ($originalKey !== $customField->key) {
                $this->renameStoredKey($customField->account_id, $originalKey, $customField->key);
            }
        });

        ActivityLogger::log('custom_field.updated', "Updated custom field {$customField->name}", [], $customField);

        return to_route('custom-fields.index')->with('success', 'Field updated.');
    }

    public function destroy(CustomField $customField): RedirectResponse
    {
        $name = $customField->name;
        $key = $customField->key;
        $accountId = $customField->account_id;

        DB::transaction(function () use ($customField, $accountId, $key) {
            $customField->delete();
            $this->forgetStoredKey($accountId, $key);
        });

        ActivityLogger::log('custom_field.deleted', "Deleted custom field {$name}");

        return to_route('custom-fields.index')
            ->with('success', "Field \"{$name}\" deleted, along with its stored values.");
    }

    /**
     * Moves every stored value from one JSON key to another, in chunks so a
     * large contact table does not load into memory.
     */
    protected function renameStoredKey(int $accountId, string $from, string $to): void
    {
        Subscriber::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->whereNotNull('custom')
            ->chunkById(500, function ($subscribers) use ($from, $to) {
                foreach ($subscribers as $subscriber) {
                    $custom = $subscriber->custom ?? [];

                    if (! array_key_exists($from, $custom)) {
                        continue;
                    }

                    $custom[$to] = $custom[$from];
                    unset($custom[$from]);

                    $subscriber->forceFill(['custom' => $custom])->saveQuietly();
                }
            });
    }

    protected function forgetStoredKey(int $accountId, string $key): void
    {
        Subscriber::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->whereNotNull('custom')
            ->chunkById(500, function ($subscribers) use ($key) {
                foreach ($subscribers as $subscriber) {
                    $custom = $subscriber->custom ?? [];

                    if (! array_key_exists($key, $custom)) {
                        continue;
                    }

                    unset($custom[$key]);
                    $subscriber->forceFill(['custom' => $custom])->saveQuietly();
                }
            });
    }
}
