<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/audit.php';

// Validate a required or optional text field and enforce its schema length.
function salonTextField($value, string $label, int $maxLength, bool $required = true): ?string
{
    $text = is_string($value) ? trim($value) : '';
    if ($required && $text === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }
    if ($text === '') {
        return null;
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : preg_match_all('/./us', $text);
    if ($length === false || $length > $maxLength) {
        throw new InvalidArgumentException($label . ' must be no more than ' . $maxLength . ' characters.');
    }
    return $text;
}

function salonOwnerInput($ownerName, $phone, $email): array
{
    $name = salonTextField($ownerName, 'Owner name', 100);
    $phoneValue = salonTextField($phone, 'Phone', 20);
    $emailValue = salonTextField($email, 'Email', 100, false);
    if ($emailValue !== null && filter_var($emailValue, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    return [$name, $phoneValue, $emailValue];
}

function salonPetInput(PDO $pdo, $petName, $species, $breed, $groomingDate, $status, $ownerId): array
{
    $name = salonTextField($petName, 'Pet name', 100);
    $speciesValue = salonTextField($species, 'Species', 50);
    $breedValue = salonTextField($breed, 'Breed', 50, false);
    $dateValue = salonTextField($groomingDate, 'Grooming date', 10, false);
    $statusValue = salonTextField($status, 'Status', 50);
    if (!in_array($statusValue, ['Pending', 'In Progress', 'Completed', 'Cancelled'], true)) {
        throw new InvalidArgumentException('Select a valid grooming status.');
    }
    if ($dateValue !== null) {
        $date = DateTime::createFromFormat('!Y-m-d', $dateValue);
        if ($date === false || $date->format('Y-m-d') !== $dateValue) {
            throw new InvalidArgumentException('Enter a valid grooming date.');
        }
    }
    $ownerIdValue = validateRecordId($ownerId, 'owner');
    $ownerCheck = $pdo->prepare('SELECT 1 FROM pet_owners WHERE owner_id = :owner_id');
    $ownerCheck->execute(['owner_id' => $ownerIdValue]);
    if ($ownerCheck->fetchColumn() === false) {
        throw new InvalidArgumentException('Select an existing owner for this pet.');
    }
    return [$name, $speciesValue, $breedValue, $dateValue, $statusValue, $ownerIdValue];
}

const PET_IMAGE_ROOT = __DIR__ . '/../uploads/pets';
const PET_IMAGE_URL_PREFIX = 'uploads/pets/';
const PET_IMAGE_MAX_SIZE = 2 * 1024 * 1024;
const PET_IMAGE_MIME_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

function ensurePetImageDir(): void
{
    if (!is_dir(PET_IMAGE_ROOT)) {
        @mkdir(PET_IMAGE_ROOT, 0775, true);
    }
}

function sanitizePetImageName(string $name): string
{
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'image';
    }
    return $name;
}

// Returns a web-root-relative image path, or null when no image is provided.
// An existing image path is replaced and its file removed when a new upload succeeds.
function uploadPetImage(array $file, ?string $existingImage = null): ?string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE || ($error === UPLOAD_ERR_OK && $file['tmp_name'] === '')) {
        return $existingImage;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The image could not be uploaded. Try again.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('The image upload failed.');
    }
    if ($file['size'] > PET_IMAGE_MAX_SIZE) {
        throw new InvalidArgumentException('The image must be no larger than 2 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($mime === false || !array_key_exists($mime, PET_IMAGE_MIME_TYPES)) {
        throw new InvalidArgumentException('Only JPEG, PNG, GIF, and WebP images are allowed.');
    }
    ensurePetImageDir();
    $extension = PET_IMAGE_MIME_TYPES[$mime];
    $filename = bin2hex(random_bytes(8)) . '_' . sanitizePetImageName(pathinfo($file['name'], PATHINFO_FILENAME)) . '.' . $extension;
    $destination = PET_IMAGE_ROOT . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new InvalidArgumentException('The image could not be saved.');
    }
    if ($existingImage !== null) {
        removePetImageFile($existingImage);
    }
    return PET_IMAGE_URL_PREFIX . $filename;
}

function removePetImageFile(?string $imagePath): void
{
    if ($imagePath === null || $imagePath === '') {
        return;
    }
    $file = PET_IMAGE_ROOT . DIRECTORY_SEPARATOR . basename($imagePath);
    if (is_file($file)) {
        @unlink($file);
    }
}

function addOwner(PDO $pdo, $ownerName, $phone, $email, int $userId): void
{
    [$name, $phoneValue, $emailValue] = salonOwnerInput($ownerName, $phone, $email);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO pet_owners (owner_name, phone, email, added_by, added_at) '
            . 'VALUES (:owner_name, :phone, :email, :added_by, CURRENT_TIMESTAMP)'
        );
        $stmt->execute(['owner_name' => $name, 'phone' => $phoneValue, 'email' => $emailValue, 'added_by' => $userId]);
        $id = (int) $pdo->lastInsertId();
        logAuditEvent($pdo, $userId, 'INSERT', 'Pet Owner', $id, null, loadAuditSnapshot($pdo, 'Pet Owner', $id));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function addPet(PDO $pdo, $petName, $species, $breed, $groomingDate, $status, $ownerId, int $userId, array $petImageFile = null): void
{
    [$name, $speciesValue, $breedValue, $dateValue, $statusValue, $ownerIdValue] =
        salonPetInput($pdo, $petName, $species, $breed, $groomingDate, $status, $ownerId);
    $imagePath = null;
    if ($petImageFile !== null && ($petImageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $imagePath = uploadPetImage($petImageFile);
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO pets (pet_name, species, breed, grooming_date, status, owner_id, pet_image, added_by, added_at) '
            . 'VALUES (:pet_name, :species, :breed, :grooming_date, :status, :owner_id, :pet_image, :added_by, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'pet_name' => $name,
            'species' => $speciesValue,
            'breed' => $breedValue,
            'grooming_date' => $dateValue,
            'status' => $statusValue,
            'owner_id' => $ownerIdValue,
            'pet_image' => $imagePath,
            'added_by' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        logAuditEvent($pdo, $userId, 'INSERT', 'Pet', $id, null, loadAuditSnapshot($pdo, 'Pet', $id));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($imagePath !== null) {
            removePetImageFile($imagePath);
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
