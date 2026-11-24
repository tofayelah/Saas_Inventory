<?php

namespace App\Modules\Crm\Requests\Lead;

use App\Modules\Crm\Models\CrmLead;
use Illuminate\Foundation\Http\FormRequest;

class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('lead');
        return $this->user() && $lead instanceof CrmLead && $this->user()->can('convert', $lead);
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:191'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
