<?php

/*
 * Display formats used by ariaieboy/filament-jalali.
 *
 * These are the formats every Jalali (Shamsi) date in the app is rendered
 * with — table columns (->jalaliDate() / ->jalaliDateTime()), infolist
 * entries, and the date pickers' visible text. Changing a value here changes
 * it everywhere at once, which is the whole point of keeping them in config
 * rather than repeating a format string at each call site.
 *
 * The letters are PHP date() letters, interpreted against the Jalali
 * calendar: Y = ۴-digit Jalali year (1405), m = month, d = day.
 *
 * DIGITS ARE LATIN ON PURPOSE. The package emits 1405/05/24, not
 * ۱۴۰۵/۰۵/۲۴, and we keep it that way so dates match the other numeric
 * columns in the panel (کد ملی, موبایل, تعداد, file sizes), all of which are
 * Latin. Only the calendar system is Persian, not the numerals.
 *
 * The default date_time_format shipped by the package includes seconds
 * ('Y/m/d H:i:s'); the app has no use for them anywhere, so they are dropped
 * here rather than overridden at each call site.
 */
return [
    'date_format' => 'Y/m/d',
    'date_time_format' => 'Y/m/d H:i',
];
