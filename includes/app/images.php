<?php
require_once __DIR__ . '/bootstrap.php';

function hub_image_upload_profiles(): array {
  return [
    'user_profile' => [
      'section' => 'content',
      'max_bytes' => 8 * 1024 * 1024,
      'max_pixels' => 25000000,
      'sizes' => [
        'lg' => 1200,
        'sm' => 150,
        'xs' => 75,
      ],
    ],
    'announcement' => [
      'section' => 'content',
      'max_bytes' => 8 * 1024 * 1024,
      'max_pixels' => 25000000,
      'sizes' => [
        'sm' => 300,
        'xs' => 150,
      ],
    ],
    'testimonial_logo' => [
      'section' => 'content',
      'max_bytes' => 8 * 1024 * 1024,
      'max_pixels' => 25000000,
      'sizes' => [
        'sm' => 360,
        'xs' => 150,
      ],
    ],
    'dashboard_banner' => [
      'section' => 'content',
      'max_bytes' => 16 * 1024 * 1024,
      'max_pixels' => 50000000,
      'min_width' => 3000,
      'min_height' => 500,
      'sizes' => [
        'lg' => 3000,
        'xs' => 150,
      ],
    ],
  ];
}

function hub_image_slug(string $value): string {
  $value = trim(mb_strtolower($value));
  if ($value === '') {
    return 'image';
  }
  $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
  if (is_string($converted) && $converted !== '') {
    $value = $converted;
  }
  $value = preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?: '';
  $value = trim($value, '-');
  return $value !== '' ? $value : 'image';
}

function hub_image_user_base_name(array $user, array $customer = null): string {
  $company = trim((string) ($customer['name'] ?? ''));
  if ($company === '') {
    $company = trim((string) ($customer['code'] ?? ''));
  }
  if ($company === '') {
    $company = 'hub';
  }

  $username = trim((string) ($user['display_name'] ?? ''));
  if ($username === '') {
    $email = trim((string) ($user['email'] ?? ''));
    $username = preg_replace('/@.*/', '', $email) ?: $email;
  }
  if ($username === '') {
    $username = 'user-' . (int) ($user['id'] ?? 0);
  }

  return hub_image_slug($company) . '-' . hub_image_slug($username);
}

function hub_image_source_from_upload(array $file, string &$error = null) {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $error = 'No image uploaded.';
    return null;
  }
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $error = 'Image upload failed.';
    return null;
  }
  $tmpName = (string) ($file['tmp_name'] ?? '');
  if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    $error = 'Uploaded image was not received correctly.';
    return null;
  }

  $info = @getimagesize($tmpName);
  if (!$info || empty($info[0]) || empty($info[1]) || empty($info[2])) {
    $error = 'Please upload a valid image file.';
    return null;
  }
  $pixels = (int) $info[0] * (int) $info[1];
  if ($pixels <= 0) {
    $error = 'Please upload a valid image file.';
    return null;
  }

  switch ((int) $info[2]) {
    case IMAGETYPE_JPEG:
      $image = @imagecreatefromjpeg($tmpName);
      $extension = 'jpg';
      break;
    case IMAGETYPE_PNG:
      $image = @imagecreatefrompng($tmpName);
      $extension = 'png';
      break;
    case IMAGETYPE_GIF:
      $image = @imagecreatefromgif($tmpName);
      $extension = 'gif';
      break;
    case IMAGETYPE_WEBP:
      $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpName) : false;
      $extension = 'webp';
      break;
    default:
      $error = 'Please upload a JPG, PNG, GIF or WebP image.';
      return null;
  }

  if (!$image) {
    $error = 'Unable to read the uploaded image.';
    return null;
  }

  if (function_exists('imagepalettetotruecolor')) {
    imagepalettetotruecolor($image);
  }
  imagealphablending($image, true);
  imagesavealpha($image, true);

  return [
    'image' => $image,
    'width' => (int) $info[0],
    'height' => (int) $info[1],
    'pixels' => $pixels,
    'extension' => $extension,
    'size' => (int) ($file['size'] ?? 0),
  ];
}

function hub_image_destination_dir(string $section, string $size): string {
  return dirname(__DIR__, 2) . '/filestore/images/' . $section . '/' . $size;
}

function hub_image_public_path(string $section, string $size, string $filename): string {
  return '/filestore/images/' . $section . '/' . $size . '/' . ltrim($filename, '/');
}

function hub_image_unique_filename(string $profileKey, string $baseName, string $extension): string {
  $profiles = hub_image_upload_profiles();
  $profile = $profiles[$profileKey] ?? null;
  if (!$profile) {
    return $baseName . '.' . $extension;
  }
  $section = (string) $profile['section'];
  $sizes = array_keys((array) $profile['sizes']);
  $baseName = hub_image_slug($baseName);
  $extension = strtolower($extension);

  for ($i = 0; $i < 1000; $i++) {
    $suffix = $i === 0 ? '' : '_' . $i;
    $filename = $baseName . $suffix . '.' . $extension;
    $webpFilename = $baseName . $suffix . '.webp';
    $exists = false;
    foreach ($sizes as $size) {
      $dir = hub_image_destination_dir($section, (string) $size);
      if (file_exists($dir . '/' . $filename) || file_exists($dir . '/' . $webpFilename)) {
        $exists = true;
        break;
      }
    }
    if (!$exists) {
      return $filename;
    }
  }

  return $baseName . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
}

function hub_image_resize_canvas($source, int $sourceWidth, int $sourceHeight, int $targetWidth) {
  $targetWidth = min($sourceWidth, $targetWidth);
  if ($targetWidth <= 0 || $targetWidth === $sourceWidth) {
    return $source;
  }
  $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
  $resized = imagecreatetruecolor($targetWidth, $targetHeight);
  imagealphablending($resized, false);
  imagesavealpha($resized, true);
  $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
  imagefilledrectangle($resized, 0, 0, $targetWidth, $targetHeight, $transparent);
  imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
  return $resized;
}

function hub_image_save_canvas($image, string $path, string $extension): bool {
  $extension = strtolower($extension);
  if ($extension === 'jpg' || $extension === 'jpeg') {
    return imagejpeg($image, $path, 88);
  }
  if ($extension === 'png') {
    return imagepng($image, $path, 6);
  }
  if ($extension === 'gif') {
    return imagegif($image, $path);
  }
  if ($extension === 'webp' && function_exists('imagewebp')) {
    return imagewebp($image, $path, 84);
  }
  return false;
}

function hub_image_upload(array $file, string $profileKey, string $baseName, string &$error = null): ?string {
  $profiles = hub_image_upload_profiles();
  $profile = $profiles[$profileKey] ?? null;
  if (!$profile) {
    $error = 'Image upload profile not found.';
    return null;
  }
  if (!extension_loaded('gd')) {
    $error = 'Image uploads are unavailable because GD is not installed.';
    return null;
  }

  $source = hub_image_source_from_upload($file, $error);
  if (!$source) {
    return null;
  }
  if ((int) $source['size'] > (int) $profile['max_bytes']) {
    imagedestroy($source['image']);
    $error = 'Image file is too large.';
    return null;
  }
  if (!empty($profile['min_width']) && (int) $source['width'] < (int) $profile['min_width']) {
    imagedestroy($source['image']);
    $error = 'Image is too narrow. Minimum width is ' . (int) $profile['min_width'] . 'px.';
    return null;
  }
  if (!empty($profile['min_height']) && (int) $source['height'] < (int) $profile['min_height']) {
    imagedestroy($source['image']);
    $error = 'Image is too short. Minimum height is ' . (int) $profile['min_height'] . 'px.';
    return null;
  }
  if ((int) $source['pixels'] > (int) $profile['max_pixels']) {
    imagedestroy($source['image']);
    $error = 'Image dimensions are too large.';
    return null;
  }

  $section = (string) $profile['section'];
  $extension = (string) $source['extension'];
  $filename = hub_image_unique_filename($profileKey, $baseName, $extension);
  $webpFilename = preg_replace('/\.[^.]+$/', '.webp', $filename) ?: ($filename . '.webp');

  foreach ((array) $profile['sizes'] as $size => $width) {
    $dir = hub_image_destination_dir($section, (string) $size);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
      imagedestroy($source['image']);
      $error = 'Unable to create image folder.';
      return null;
    }
    if (!is_writable($dir)) {
      imagedestroy($source['image']);
      $error = 'Image folder is not writable.';
      return null;
    }

    $canvas = hub_image_resize_canvas($source['image'], (int) $source['width'], (int) $source['height'], (int) $width);
    $ownsCanvas = $canvas !== $source['image'];
    $target = $dir . '/' . $filename;
    $targetWebp = $dir . '/' . $webpFilename;
    if (!hub_image_save_canvas($canvas, $target, $extension) || !hub_image_save_canvas($canvas, $targetWebp, 'webp')) {
      if ($ownsCanvas) {
        imagedestroy($canvas);
      }
      imagedestroy($source['image']);
      $error = 'Unable to save resized image.';
      return null;
    }
    @chmod($target, 0664);
    @chmod($targetWebp, 0664);
    if ($ownsCanvas) {
      imagedestroy($canvas);
    }
  }

  imagedestroy($source['image']);
  return $filename;
}
