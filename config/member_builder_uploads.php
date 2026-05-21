<?php

/**
 * Limites de upload do Member Builder (kilobytes na regra Laravel `max:`).
 */
return [
    'image_max_kb' => (int) env('MEMBER_BUILDER_UPLOAD_IMAGE_MAX_KB', 10240),
    'badge_image_max_kb' => (int) env('MEMBER_BUILDER_UPLOAD_BADGE_IMAGE_MAX_KB', 5120),
    'pdf_max_kb' => (int) env('MEMBER_BUILDER_UPLOAD_PDF_MAX_KB', 51200),
];
