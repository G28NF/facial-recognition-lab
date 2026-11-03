<?php
declare(strict_types=1);

use App\Core\Env;

$integer = static function (string $name, int $default, int $minimum, int $maximum): int {
    $value = filter_var(Env::get($name, (string) $default), FILTER_VALIDATE_INT);
    return $value === false ? $default : max($minimum, min($maximum, $value));
};

return [
    'maximum_people' => $integer('RFV2_IMPORT_MAX_PEOPLE', 100, 1, 2_000),
    'maximum_images_per_person' => $integer('RFV2_IMPORT_MAX_IMAGES_PER_PERSON', 10, 2, 1_000),
    'maximum_files' => $integer('RFV2_IMPORT_MAX_FILES', 10_000, 2, 10_000),
    'maximum_image_bytes' => $integer('RFV2_IMPORT_MAX_IMAGE_BYTES', 5_000_000, 100_000, 10_000_000),
    'maximum_archive_bytes' => $integer('RFV2_IMPORT_MAX_ARCHIVE_BYTES', 120_000_000, 1_000_000, 120_000_000),
    'maximum_uncompressed_bytes' => $integer('RFV2_IMPORT_MAX_UNCOMPRESSED_BYTES', 200_000_000, 2_000_000, 500_000_000),
    'maximum_pixels' => $integer('RFV2_IMPORT_MAX_PIXELS', 25_000_000, 10_000, 50_000_000),
    'minimum_dimension' => $integer('RFV2_IMPORT_MIN_DIMENSION', 40, 20, 500),
    'maximum_compression_ratio' => $integer('RFV2_IMPORT_MAX_COMPRESSION_RATIO', 100, 5, 500),
    'temporary_root' => rtrim(Env::get('RFV2_IMPORT_TEMP_ROOT', sys_get_temp_dir().DIRECTORY_SEPARATOR.'rfv2-imports') ?? '', "\\/"),
];
