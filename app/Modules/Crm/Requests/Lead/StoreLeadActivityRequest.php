<?php

namespace App\Modules\Crm\Requests\Lead;

use App\Models\Membership;
use App\Models\Team;
use App\Modules\Crm\Models\CrmLead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadActivityRequest extends FormRequest
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
            'activity_type' => ['required', Rule::in(['call', 'meeting', 'task', 'note', 'email', 'whatsapp'])],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date'],
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
        ];
    }
}
