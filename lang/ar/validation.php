<?php

/*
|--------------------------------------------------------------------------
| Arabic Validation Messages (SkyCast)
|--------------------------------------------------------------------------
|
| Laravel ships English validation messages in the framework, so only the
| messages SkyCast actually produces need an Arabic copy. The rules below
| cover every validation rule used by WeatherController.
|
*/

return [

    'required' => 'حقل :attribute مطلوب.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'integer' => 'يجب أن يكون :attribute عددًا صحيحًا.',
    'boolean' => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة.',
    'array' => 'يجب أن يكون :attribute قائمة.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صحيحًا.',
    'url' => 'يجب أن يكون :attribute رابطًا صحيحًا.',
    'date' => 'يجب أن يكون :attribute تاريخًا صحيحًا.',

    'in' => 'القيمة المختارة في :attribute غير صحيحة.',

    'between' => [
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يكون عدد أحرف :attribute بين :min و :max.',
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و :max.',
        'file' => 'يجب أن يكون حجم ملف :attribute بين :min و :max كيلوبايت.',
    ],

    'min' => [
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string' => 'يجب ألا يقل عدد أحرف :attribute عن :min.',
        'array' => 'يجب أن يحتوي :attribute على :min عنصر على الأقل.',
        'file' => 'يجب ألا يقل حجم ملف :attribute عن :min كيلوبايت.',
    ],

    'max' => [
        'numeric' => 'يجب ألا تزيد قيمة :attribute عن :max.',
        'string' => 'يجب ألا يزيد عدد أحرف :attribute عن :max.',
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصر.',
        'file' => 'يجب ألا يزيد حجم ملف :attribute عن :max كيلوبايت.',
    ],

    'attributes' => [
        'city' => 'اسم المدينة',
        'lat' => 'خط العرض',
        'lon' => 'خط الطول',
        'units' => 'وحدة القياس',
        'locale' => 'اللغة',
    ],

];
