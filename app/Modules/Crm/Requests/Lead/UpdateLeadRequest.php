<?php

namespace App\Modules\Crm\Requests\Lead;

use App\Models\Membership;
use App\Models\Team;
use App\Models\Warehouse;
use App\Modules\Crm\Models\CrmLead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('lead');
        return $this->user() && $lead instanceof CrmLead && $this->user()->can('update', $lead);
    }

    public function rules(): array
    {
        $teamId = $this->user()?->current_team_id;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:191'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'source' => ['sometimes', 'required', Rule::in(['manual', 'whatsapp', 'website', 'referral', 'campaign', 'import'])],
            'status' => ['sometimes', 'required', Rule::in(['new', 'contacted', 'qualified', 'unqualified', 'converted'])],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'assigned_user_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($teamId) {
                    if (!$value || !$teamId) return;
                    $team = Team::find($teamId);
                    if (!$team) {
                        $fail('Active tenant not found.');
                        return;
                    }
                    $isMemberOrOwner = ($team->user_id === (int) $value) ||
                        Membership::where('team_id', $teamId)->where('user_id', $value)->exists();
                    if (!$isMemberOrOwner) {
                        $fail('The selected assigned user is not a member of the active workspace.');
                    }
                },
            ],
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'custom_fields' => ['nullable', 'array'],
        ];
    }
}
