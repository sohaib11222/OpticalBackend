<?php

namespace App\Services;

use App\Models\ContactLensRecord;
use App\Models\GlassesRecord;
use App\Models\OpticalRecord;
use App\Models\OptometryRecord;
use App\Models\Prescription;
use App\Models\VisualExamination;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OpticalDictionary
{
    public const MODELS = ['optometry' => OptometryRecord::class, 'examination' => VisualExamination::class, 'prescription' => Prescription::class, 'contact_lens' => ContactLensRecord::class, 'glasses' => GlassesRecord::class];

    public const LABELS = ['optometry' => 'Optometry', 'examination' => 'Visual examination', 'prescription' => 'Prescription', 'contact_lens' => 'Contact lenses', 'glasses' => 'Glasses'];

    public static function fields(string $kind): array
    {
        abort_unless(isset(self::MODELS[$kind]), 404);
        $out = [];
        $number = function ($key, $label, $min, $max, $unit, $step = '0.01') use (&$out) {
            $out[$key] = ['label' => $label, 'type' => 'number', 'min' => $min, 'max' => $max, 'unit' => $unit, 'step' => $step];
        };
        $text = function ($key, $label, $max = 255, $type = 'text') use (&$out) {
            $out[$key] = ['label' => $label, 'type' => $type, 'maxLength' => $max];
        };
        if (in_array($kind, ['optometry', 'examination', 'prescription'])) {
            foreach (['od' => 'Right eye (OD)', 'os' => 'Left eye (OS)'] as $eye => $name) {
                $number($eye.'_sphere', $name.' sphere', -30, 30, 'D');
                $number($eye.'_cylinder', $name.' cylinder', -10, 10, 'D');
                $number($eye.'_axis', $name.' axis', 0, 180, 'degrees', '1');
                $number($eye.'_add', $name.' addition', 0, 5, 'D');
            }
            $number('pd_binocular', 'Binocular pupillary distance', 50, 80, 'mm');
            $number('pd_monocular_od', 'Right pupillary distance', 25, 40, 'mm');
            $number('pd_monocular_os', 'Left pupillary distance', 25, 40, 'mm');
            $number('pd_near', 'Near pupillary distance', 50, 80, 'mm');
        }
        if ($kind === 'prescription') {
            $out['prescription_type'] = ['label' => 'Prescription type', 'type' => 'select', 'options' => ['single_vision', 'bifocal', 'trifocal', 'progressive'], 'required' => true];
            $number('ph_od', 'Right horizontal prism', -999.99, 999.99, 'prism diopters');
            $number('ph_os', 'Left horizontal prism', -999.99, 999.99, 'prism diopters');
            $text('doctor_license', 'Practitioner license', 100);
            $text('expiry_date', 'Expiry date', 10, 'date');
        }
        if ($kind === 'examination') {
            $text('reason_for_visit', 'Reason for visit', 2000, 'textarea');
            $text('observations', 'Examination observations', 5000, 'textarea');
        }
        if ($kind === 'optometry') {
            $text('measurement_context', 'Measurement method / context', 2000, 'textarea');
        }
        if ($kind === 'contact_lens') {
            foreach (['od' => 'Right eye (OD)', 'os' => 'Left eye (OS)'] as $eye => $name) {
                $number($eye.'_power', $name.' power', -30, 30, 'D');
                $number($eye.'_cylinder', $name.' cylinder', -10, 10, 'D');
                $number($eye.'_axis', $name.' axis', 0, 180, 'degrees', '1');
                $number($eye.'_base_curve', $name.' base curve', 0.01, 99.99, 'mm');
                $number($eye.'_diameter', $name.' diameter', 0.01, 99.99, 'mm');
            }
            foreach (['brand' => 'Lens brand', 'model' => 'Lens model', 'material' => 'Material', 'lens_type' => 'Lens type', 'replacement_frequency' => 'Replacement frequency', 'color' => 'Color'] as $key => $label) {
                $text($key, $label, 150);
            }
        }
        if ($kind === 'glasses') {
            foreach (['brand' => 'Frame brand', 'model' => 'Frame model', 'color' => 'Frame color', 'frame_size' => 'Frame size', 'lens_type' => 'Lens type', 'lens_material' => 'Lens material', 'treatment' => 'Lens treatment'] as $key => $label) {
                $text($key, $label, 150);
            }
            $number('fitting_height_od', 'Right fitting height', 0.01, 999.99, 'mm');
            $number('fitting_height_os', 'Left fitting height', 0.01, 999.99, 'mm');
        }
        $text('notes', 'Notes', 5000, 'textarea');

        return $out;
    }

    public static function rules(string $kind, bool $final = false): array
    {
        $fields = self::fields($kind);
        $rules = ['data' => ['required', 'array:'.implode(',', array_keys($fields))]];
        foreach ($fields as $key => $field) {
            $rule = ($final && ($field['required'] ?? false)) ? 'required' : 'nullable';
            $rules['data.'.$key] = match ($field['type']) {
                'number' => [$rule, $field['step'] === '1' ? 'integer' : 'numeric', 'between:'.$field['min'].','.$field['max'], 'decimal:0,2'],
                'select' => [$rule, Rule::in($field['options'])],
                'date' => [$rule, 'date_format:Y-m-d'],
                default => [$rule, 'string', 'max:'.$field['maxLength']]
            };
        }

        return $rules;
    }

    public static function validateFinal(OpticalRecord $record): void
    {
        validator(['data' => $record->data], self::rules($record->kind, true))->validate();
        $d = $record->data;
        $errors = [];
        if (in_array($record->kind, ['prescription', 'optometry', 'examination']) && ! isset($d['od_sphere']) && ! isset($d['os_sphere'])) {
            $errors['data.od_sphere'] = 'Record at least one eye sphere measurement before finalizing.';
        }
        if ($record->kind === 'contact_lens') {
            if (! isset($d['od_power']) && ! isset($d['os_power'])) {
                $errors['data.od_power'] = 'Record at least one eye power before finalizing.';
            }
            foreach (['od', 'os'] as $eye) {
                if (isset($d[$eye.'_power'])) {
                    foreach (['base_curve', 'diameter'] as $field) {
                        if (! isset($d[$eye.'_'.$field])) {
                            $errors['data.'.$eye.'_'.$field] = 'Record the lens '.$field.' for this eye.';
                        }
                    }
                }
            }
        }
        if ($record->kind === 'glasses') {
            foreach (['brand', 'model', 'color'] as $key) {
                if (empty($d[$key])) {
                    $errors['data.'.$key] = 'This frame detail is required before finalizing.';
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
