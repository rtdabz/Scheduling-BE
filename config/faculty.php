<?php

/*
 * Teaching-load defaults for instructor records created without explicit load
 * values: a new roster entry, or the instructor profile created alongside a new
 * account. Existing instructors keep whatever load is stored on their record.
 */
return [
    'default_max_units' => (int) env('FACULTY_DEFAULT_MAX_UNITS', 21),
];
